<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Orders;

use WalletPlatform\Application\SplitPaymentService;
use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class PayPalOrders
{
    private array $refundContext = [];
    private array $refundArgs = [];

    public function __construct(private readonly Services $services, public readonly SplitPaymentService $payments)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_create_refund', function (\WC_Order_Refund $refund, array $args): void {
            if (isset($args['wallet_payment_refund_id'])) {
                $refund->update_meta_data('_wallet_payment_refund_id', (string) $args['wallet_payment_refund_id']);
                $refund->set_refunded_payment(true);
            }
            $this->refundContext[$refund->get_parent_id()] = $refund;
            $this->refundArgs[$refund->get_parent_id()] = $args;
        }, 10, 2);
        add_action('woocommerce_order_status_cancelled', [$this, 'onFailure']);
        add_action('woocommerce_order_status_failed', [$this, 'onFailure']);
        add_filter('woocommerce_available_payment_gateways', function (array $gateways): array {
            if (!is_checkout_pay_page()) {
                return $gateways;
            }
            $order = wc_get_order(absint(get_query_var('order-pay')));
            if (!$order instanceof \WC_Order || $order->get_customer_id() !== get_current_user_id()) {
                return $gateways;
            }
            $payment = $this->forOrder($order->get_id());
            return $payment !== null && !in_array($payment['state'], ['cancelled', 'compensated'], true) ? array_intersect_key($gateways, ['wallet_platform_paypal' => true]) : $gateways;
        });
    }

    public function start(\WC_Order $order, ?Money $requestedWallet = null, ?Money $expectedGross = null): array
    {
        return $this->locked($order->get_id(), function () use ($order, $requestedWallet, $expectedGross): array {
            $current = wc_get_order($order->get_id());
            if (!$current instanceof \WC_Order || $current->get_customer_id() !== get_current_user_id() || !$current->get_customer_id() || $current->get_meta('_wallet_topup_id') || $current->get_payment_method() !== 'wallet_platform_paypal') {
                throw new WalletException('unauthorized_order', 'Split tender requires the owner’s unpaid merchandise order.');
            }
            $previous = $this->forOrder($current->get_id());
            if ($current->is_paid() && $previous !== null && $this->matches($current, $previous) && $current->get_transaction_id() === $this->transactionId($previous)) {
                return $previous;
            }
            if (!in_array($current->get_status(), ['pending', 'checkout-draft', 'on-hold'], true)) {
                throw new WalletException('invalid_order_state', 'Order is not awaiting split payment.');
            }
            $currency = Currency::of($current->get_currency());
            $gross = Money::fromDecimal((string) $current->get_total('edit'), $currency);
            $account = $this->services->wallets->account($current->get_customer_id(), $currency);
            $available = Money::fromDecimal($this->services->queries->balance($account['id'])['available'], $currency);
            $wallet = $previous === null ? ($requestedWallet ?? $available) : new Money((int) $previous['wallet_amount'], $currency);
            if ($previous === null && (($expectedGross !== null && $expectedGross->compare($gross) !== 0) || $wallet->compare($available) > 0)) {
                throw new WalletException('payment_quote_changed', 'Review the changed balance or checkout total before paying.');
            }
            $payment = $this->payments->prepare($current->get_id(), $account['id'], $gross, $wallet, 'wc:split:prepare:' . $current->get_id(), $current->get_customer_id());
            $current->update_meta_data('_wallet_split_payment_id', $payment['id']);
            $current->save();
            return $this->payments->start($payment['id'], $current->get_customer_id(), $this->callbackUrl($payment, $current, false), $this->callbackUrl($payment, $current, true));
        });
    }

    public function recover(string $id): array
    {
        $payment = $this->payments->record($id);
        return $this->locked((int) $payment['order_id'], function () use ($id): array {
            $payment = $this->payments->record($id);
            $order = wc_get_order((int) $payment['order_id']);
            if (!$order instanceof \WC_Order || !$this->matches($order, $payment) || in_array($order->get_status(), ['failed', 'cancelled'], true) || ($order->is_paid() && $order->get_transaction_id() !== $this->transactionId($payment))) {
                return $this->payments->cancel($id, (int) $payment['owner_id']);
            }
            if ($order->get_status() === 'refunded') {
                return $payment['state'] === 'completed' && $order->get_transaction_id() === $this->transactionId($payment) ? $payment : $this->payments->cancel($id, (int) $payment['owner_id']);
            }
            $payment = $this->payments->recover($id, in_array($order->get_status(), ['pending', 'on-hold', 'checkout-draft'], true));
            if ($payment['state'] === 'completed') {
                $hold = $this->services->holds->hold($payment['hold_id']);
                if ($hold['state'] !== 'captured' || (int) $hold['captured'] !== (int) $payment['wallet_amount'] || !(int) $payment['external_settled'] || $payment['capture_id'] === null || (!$order->is_paid() && (int) $hold['refunded'] > 0)) {
                    throw new WalletException('allocation_conflict', 'Completed split tender does not match its order capture.');
                }
                if (!$order->is_paid()) {
                    $order->update_meta_data('_wallet_split_payment_id', $id);
                    $order->update_meta_data('_wallet_allocation_id', $payment['allocation_id']);
                    $order->update_meta_data('_wallet_hold_id', $payment['hold_id']);
                    $order->save();
                    $order->payment_complete($this->transactionId($payment));
                }
            } elseif (in_array($payment['state'], ['review', 'capture_pending', 'cancellation_pending', 'compensating'], true) && $order->get_status() !== 'on-hold') {
                $order->update_status('on-hold', __('PayPal payment is awaiting confirmation. Do not collect another payment for this order.', 'wallet-platform'));
            }
            return $payment;
        });
    }

    public function onFailure(int $orderId): void
    {
        $order = wc_get_order($orderId);
        $payment = $this->forOrder($orderId);
        if ($payment !== null && $order instanceof \WC_Order && in_array($order->get_status(), ['failed', 'cancelled'], true)) {
            $this->locked($orderId, function () use ($orderId, $payment): array {
                $current = wc_get_order($orderId);
                return $current instanceof \WC_Order && in_array($current->get_status(), ['failed', 'cancelled'], true) ? $this->payments->cancel($payment['id'], (int) $payment['owner_id']) : $payment;
            });
        }
    }

    public function cancelByOwner(string $id, int $owner): array
    {
        $payment = $this->payments->record($id);
        return $this->locked((int) $payment['order_id'], function () use ($id, $owner): array {
            $payment = $this->payments->record($id);
            $order = wc_get_order((int) $payment['order_id']);
            if (!$order instanceof \WC_Order || $owner <= 0 || (int) $payment['owner_id'] !== $owner || $order->get_customer_id() !== $owner || !$this->matches($order, $payment)) {
                throw new WalletException('payment_owner_mismatch', 'Cancellation requires the order owner.');
            }
            if ($payment['state'] === 'completed' || $order->is_paid()) {
                return $payment; // An old approval-session cancel URL is never a right to refund a paid order.
            }
            if (!in_array($order->get_status(), ['pending', 'checkout-draft', 'on-hold'], true)) {
                throw new WalletException('invalid_order_state', 'Order cannot be cancelled from this approval session.');
            }
            $payment = $this->payments->cancel($id, $owner);
            $order->update_status('cancelled', __('Customer cancelled PayPal wallet checkout.', 'wallet-platform'));
            return $payment;
        });
    }

    public function gatewayRefund(int $orderId, mixed $amount): bool|\WP_Error
    {
        if (!current_user_can('wallet_credit')) {
            return new \WP_Error('wallet_refund_forbidden', __('Wallet refund requires permission to restore wallet credit.', 'wallet-platform'));
        }
        $original = $this->refundContext[$orderId] ?? null;
        $args = $this->refundArgs[$orderId] ?? [];
        try {
            $order = wc_get_order($orderId);
            $refund = $original === null ? null : wc_get_order($original->get_id());
            $payment = $this->forOrder($orderId);
            if (!$order instanceof \WC_Order || !$refund instanceof \WC_Order_Refund || $refund->get_parent_id() !== $orderId || $payment === null || !$this->matches($order, $payment) || $order->get_transaction_id() !== $this->transactionId($payment)) {
                throw new WalletException('refund_context_missing', 'Split refund requires its persisted order and refund reference.');
            }
            $currency = Currency::of($order->get_currency());
            $money = Money::fromDecimal(wc_format_decimal($amount, $currency->exponent), $currency);
            if ($money->compare(Money::fromDecimal(wc_format_decimal($refund->get_amount(), $currency->exponent), $currency)) !== 0 || count($args['line_items'] ?? []) > 100) {
                throw new WalletException('refund_conflict', 'Split refund differs from its persisted snapshot.');
            }
            $intent = $this->payments->prepareRefundIntent($payment['id'], $money, 'wc:split:refund:' . $refund->get_id(), ['order_id' => $orderId, 'original_refund_id' => $refund->get_id(), 'actor_id' => get_current_user_id(), 'reason' => substr($refund->get_reason(), 0, 500), 'line_items' => $args['line_items'] ?? []]);
            $refund->update_meta_data('_wallet_payment_refund_id', $intent['id']);
            $refund->save();
            $result = $this->payments->recoverRefund($intent['id']);
            if ($result['state'] !== 'completed') {
                throw new WalletException('refund_pending', 'External refund is pending.');
            }
            return true;
        } catch (\Throwable $error) {
            wc_get_logger()->error('PayPal split refund needs review: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => $orderId]);
            if (isset($order) && $order instanceof \WC_Order) {
                $order->add_order_note(__('Split refund is awaiting provider confirmation or review. Do not issue another refund; recovery uses the original request.', 'wallet-platform'));
            }
            return new \WP_Error('wallet_split_refund_review', __('Refund is awaiting confirmation or review. Check this order’s wallet payment status before retrying.', 'wallet-platform'));
        } finally {
            unset($this->refundContext[$orderId], $this->refundArgs[$orderId]);
        }
    }

    /** Recover WooCommerce CRUD after verified external and wallet refunds, including deleted pending refund objects. */
    public function recoverRefund(string $id): void
    {
        $intent = $this->payments->recoverRefund($id);
        if ($intent['state'] !== 'completed') {
            return;
        }
        $payment = $this->payments->record($intent['payment_id']);
        $context = $intent['adapter_context'] === null ? ['order_id' => (int) $payment['order_id'], 'original_refund_id' => 0, 'reason' => __('Split payment restoration after cancellation', 'wallet-platform'), 'line_items' => []] : json_decode($intent['adapter_context'], true, 32, JSON_THROW_ON_ERROR);
        $this->locked((int) $payment['order_id'], function () use ($intent, $context, $payment): array {
            $order = wc_get_order((int) $payment['order_id']);
            if (!$order instanceof \WC_Order || !$this->matches($order, $payment) || (int) $context['order_id'] !== $order->get_id()) {
                throw new WalletException('refund_conflict', 'Refund recovery order changed.');
            }
            if ($intent['adapter_context'] === null && $order->get_date_paid() === null) {
                if ($order->get_meta('_wallet_compensation_recorded') !== $intent['id']) {
                    $order->add_order_note(__('External payment compensation is confirmed. The order was never paid from the wallet or released for fulfillment.', 'wallet-platform'));
                    $order->update_meta_data('_wallet_compensation_recorded', $intent['id']);
                    $order->save();
                }
                return [];
            }
            if ($order->get_transaction_id() !== $this->transactionId($payment)) {
                throw new WalletException('refund_conflict', 'Refund recovery requires the original payment transaction.');
            }
            $found = null;
            foreach ($order->get_refunds() as $refund) {
                if ($refund->get_id() === (int) $context['original_refund_id'] || $refund->get_meta('_wallet_payment_refund_id') === $intent['id']) {
                    $found = $refund;
                    break;
                }
            }
            $amount = new Money((int) $intent['gross'], Currency::of($payment['currency']));
            if ($found === null) {
                $found = wc_create_refund(['order_id' => $order->get_id(), 'amount' => $amount->decimal(), 'reason' => $context['reason'], 'line_items' => $context['line_items'], 'refund_payment' => false, 'restock_items' => false, 'wallet_payment_refund_id' => $intent['id']]);
                if (is_wp_error($found)) {
                    throw new WalletException('refund_order_recovery', 'Settled refund could not be recorded in WooCommerce.');
                }
                $order->add_order_note(__('Delayed split refund is settled and recorded. Check inventory separately if the original request included restocking.', 'wallet-platform'));
            }
            if ($amount->compare(Money::fromDecimal(wc_format_decimal($found->get_amount(), $amount->currency->exponent), $amount->currency)) !== 0) {
                throw new WalletException('refund_conflict', 'Recovered refund amount differs.');
            }
            $found->update_meta_data('_wallet_payment_refund_id', $intent['id']);
            $found->update_meta_data('_wallet_payment_refund_state', 'completed');
            $found->set_refunded_payment(true);
            $found->save();
            // A process can stop after the refund's first save, before WooCommerce updates its parent.
            $current = wc_get_order($order->get_id());
            if (!$current instanceof \WC_Order) {
                throw new WalletException('refund_order_recovery', 'Refund parent could not be reloaded.');
            }
            $refunded = new Money(0, $amount->currency);
            foreach ($current->get_refunds() as $record) {
                $refunded = $refunded->add(Money::fromDecimal(wc_format_decimal($record->get_amount(), $amount->currency->exponent), $amount->currency));
            }
            $gross = new Money((int) $payment['gross'], $amount->currency);
            if ($refunded->compare($gross) > 0) {
                throw new WalletException('refund_conflict', 'WooCommerce refunds exceed the original payment.');
            }
            if ($refunded->compare($gross) === 0 && (!$current->has_free_item() || $current->get_remaining_refund_items() <= 0) && $current->get_status() !== 'refunded') {
                $status = apply_filters('woocommerce_order_fully_refunded_status', 'refunded', $current->get_id(), $found->get_id());
                if ($status) {
                    $current->update_status($status, __('Verified split refund recovery completed the order refund.', 'wallet-platform'));
                }
            }
            return [];
        });
    }

    public function forOrder(int $orderId): ?array
    {
        $db = $this->services->db;
        $row = $db->row('SELECT p.id FROM ' . $db->table('payments') . ' p JOIN ' . $db->table('allocations') . ' a ON a.id=p.allocation_id WHERE a.order_id=? ORDER BY a.attempt DESC LIMIT 1', [$orderId]);
        return $row === null ? null : $this->payments->record($row['id']);
    }

    public function callbackUrl(array $payment, \WC_Order $order, bool $cancel): string
    {
        return add_query_arg(['wc-api' => $cancel ? 'wallet_platform_paypal_cancel' : 'wallet_platform_paypal_return', 'payment_id' => $payment['id'], 'order_key' => $order->get_order_key(), 'wallet_nonce' => wp_create_nonce('wallet_paypal_' . $payment['id'] . '_' . $payment['owner_id'])], home_url('/'));
    }

    private function matches(\WC_Order $order, array $payment): bool
    {
        return $order->get_payment_method() === 'wallet_platform_paypal' && $order->get_customer_id() === (int) $payment['owner_id'] && !$order->get_meta('_wallet_topup_id') && $order->get_currency() === $payment['currency'] && Money::fromDecimal((string) $order->get_total('edit'), Currency::of($order->get_currency()))->minor === (int) $payment['gross'];
    }

    private function transactionId(array $payment): string
    {
        return 'wallet-paypal:' . $payment['id'] . ':' . $payment['capture_id'];
    }

    private function locked(int $orderId, callable $work): array
    {
        $db = $this->services->db;
        $key = 'wallet_order_' . $orderId;
        if ((int) ($db->row('SELECT GET_LOCK(?,10) AS acquired', [$key])['acquired'] ?? 0) !== 1) {
            throw new WalletException('concurrency_conflict', 'Order payment is already processing.');
        }
        try {
            return $work();
        } finally {
            $db->row('SELECT RELEASE_LOCK(?) AS released', [$key]);
        }
    }
}
