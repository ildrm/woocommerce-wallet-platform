<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Orders;

use WalletPlatform\Application\CommandContext;
use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class OrderPayments
{
    /** @var array<int,\WC_Order_Refund> */
    private array $refundContext = [];

    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_create_refund', function (\WC_Order_Refund $refund): void {
            $this->refundContext[$refund->get_parent_id()] = $refund;
        });
        add_action('woocommerce_order_refunded', [$this, 'onRefund'], 10, 2);
        add_action('woocommerce_order_status_cancelled', [$this, 'onFailure']);
        add_action('woocommerce_order_status_failed', [$this, 'onFailure']);
    }

    public function pay(\WC_Order $order): array
    {
        return $this->locked($order->get_id(), function () use ($order): array {
            $current = wc_get_order($order->get_id());
            if (!$current instanceof \WC_Order) {
                throw new WalletException('order_not_found', 'Order does not exist.');
            }
            return $this->payLocked($current);
        });
    }

    private function payLocked(\WC_Order $order): array
    {
        if (!$order->get_customer_id() || $order->get_customer_id() !== get_current_user_id() || $order->get_meta('_wallet_topup_id') || $order->get_payment_method() !== 'wallet_platform') {
            throw new WalletException('unauthorized_order', 'Wallet can pay only its owner’s merchandise order.');
        }
        if (!in_array($order->get_status(), ['pending', 'failed', 'checkout-draft'], true) && !$order->is_paid()) {
            throw new WalletException('invalid_order_state', 'Order is not awaiting payment.');
        }
        $currency = Currency::of($order->get_currency());
        $gross = Money::fromDecimal((string) $order->get_total('edit'), $currency);
        $gross->positive();
        $account = $this->services->wallets->account($order->get_customer_id(), $currency);
        $db = $this->services->db;
        $allocation = $db->transaction(function () use ($db, $order, $gross, $account): array {
            $db->row('SELECT id FROM ' . $db->table('accounts') . ' WHERE id=?' . $db->lockSuffix(), [$account['id']]);
            $previous = $db->row('SELECT * FROM ' . $db->table('allocations') . ' WHERE order_id=? ORDER BY attempt DESC LIMIT 1' . $db->lockSuffix(), [$order->get_id()]);
            if ($previous !== null) {
                if ($previous['account_id'] !== $account['id'] || (int) $previous['gross'] !== $gross->minor || (int) $previous['wallet_amount'] !== $gross->minor || (int) $previous['external_amount'] !== 0 || $previous['currency'] !== $gross->currency->code) {
                    throw new WalletException('allocation_conflict', 'Order changed after wallet payment allocation.');
                }
                if ($previous['hold_id'] === null && (int) $previous['expires_at'] > $this->services->clock->now()) {
                    return $previous;
                }
                if ($previous['hold_id'] !== null) {
                    $hold = $this->services->holds->hold($previous['hold_id']);
                    if (in_array($hold['state'], ['held', 'captured'], true)) {
                        return $previous;
                    }
                }
            }
            if ($order->is_paid()) {
                throw new WalletException('order_already_paid', 'Paid order has no matching wallet capture.');
            }
            $record = ['id' => CommandContext::id(), 'order_id' => $order->get_id(), 'attempt' => $previous === null ? 1 : (int) $previous['attempt'] + 1, 'account_id' => $account['id'], 'gross' => $gross->minor, 'wallet_amount' => $gross->minor, 'external_amount' => 0, 'currency' => $gross->currency->code, 'expires_at' => $this->services->clock->now() + 1800, 'created_at' => $this->services->clock->now(), 'hold_id' => null];
            $db->insert('allocations', $record);
            return $record;
        });
        if ($order->is_paid()) {
            if ($allocation['hold_id'] === null || $this->services->holds->hold($allocation['hold_id'])['state'] !== 'captured' || $order->get_transaction_id() !== 'wallet:' . $allocation['id']) {
                throw new WalletException('order_already_paid', 'Order payment does not match the wallet capture.');
            }
            return ['allocation_id' => $allocation['id'], 'hold_id' => $allocation['hold_id']];
        }
        $holdId = $allocation['hold_id'];
        if ($holdId === null) {
            $reservation = $this->services->holds->reserve($account['id'], $gross, 'wc:allocation:' . $allocation['id'], (int) $allocation['expires_at'], 'wc:reserve:' . $allocation['id'], $order->get_customer_id());
            $holdId = $reservation['hold_id'];
            $db->execute('UPDATE ' . $db->table('allocations') . ' SET hold_id=? WHERE id=? AND hold_id IS NULL', [$holdId, $allocation['id']]);
        }
        $this->services->holds->capture($holdId, 'wc:capture:' . $allocation['id'], $order->get_customer_id());
        // Order CRUD is a post-commit saga: a retry recovers the same capture after an interrupted save.
        $order->update_meta_data('_wallet_allocation_id', $allocation['id']);
        $order->update_meta_data('_wallet_hold_id', $holdId);
        $order->save();
        $order->payment_complete('wallet:' . $allocation['id']);
        return ['allocation_id' => $allocation['id'], 'hold_id' => $holdId];
    }

    public function gatewayRefund(int $orderId, mixed $amount, string $reason): bool|\WP_Error
    {
        $refund = $this->refundContext[$orderId] ?? null;
        if ($refund === null || $refund->get_id() <= 0 || $refund->get_parent_id() !== $orderId) {
            return new \WP_Error('wallet_refund_context', __('Create the refund through WooCommerce so it has a stable refund reference.', 'wallet-platform'));
        }
        try {
            $persisted = wc_get_order($refund->get_id());
            if (!$persisted instanceof \WC_Order_Refund || $persisted->get_parent_id() !== $orderId) {
                throw new WalletException('refund_context_missing', 'Refund reference is no longer persisted.');
            }
            $refund = $persisted;
            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order || $order->get_payment_method() !== 'wallet_platform') {
                throw new WalletException('order_not_found', 'Order does not exist.');
            }
            $currency = Currency::of($order->get_currency());
            $requested = Money::fromDecimal(wc_format_decimal($amount, $currency->exponent), $currency);
            $recorded = Money::fromDecimal(wc_format_decimal($refund->get_amount(), $currency->exponent), $currency);
            if ($requested->compare($recorded) !== 0) {
                throw new WalletException('refund_conflict', 'Refund context amount differs.');
            }
            $this->restore($order, $refund);
            return true;
        } catch (\Throwable $error) {
            unset($this->refundContext[$orderId]);
            wc_get_logger()->error('Wallet refund failed: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => $orderId]);
            return new \WP_Error('wallet_refund_failed', __('Wallet refund could not be completed. Check its status before retrying.', 'wallet-platform'));
        }
    }

    public function onRefund(int $orderId, int $refundId): void
    {
        $order = wc_get_order($orderId);
        $refund = wc_get_order($refundId);
        if (!$order instanceof \WC_Order || !$refund instanceof \WC_Order_Refund || $order->get_payment_method() !== 'wallet_platform') {
            return;
        }
        try {
            $this->restore($order, $refund);
        } catch (\Throwable $error) {
            $order->add_order_note(__('Wallet refund requires review. Retry the refund event with its original reference.', 'wallet-platform'));
            wc_get_logger()->error('Wallet refund event failed: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => $orderId, 'refund_id' => $refundId]);
        }
        unset($this->refundContext[$orderId]);
    }

    private function restore(\WC_Order $order, \WC_Order_Refund $refund): void
    {
        $allocation = $this->allocation($order->get_id());
        if ($allocation === null || (int) $allocation['external_amount'] !== 0 || $allocation['hold_id'] === null || $refund->get_parent_id() !== $order->get_id()) {
            throw new WalletException('allocation_missing', 'Refund has no wallet allocation.');
        }
        $money = Money::fromDecimal(wc_format_decimal($refund->get_amount(), Currency::of($order->get_currency())->exponent), Currency::of($order->get_currency()));
        if ($money->minor === 0) {
            return;
        }
        $this->services->refunds->refund($allocation['hold_id'], $money, 'wc:refund:' . $refund->get_id(), 0, 'WooCommerce refund #' . $refund->get_id());
    }

    public function onFailure(int $orderId): void
    {
        $db = $this->services->db;
        $lock = 'wallet_order_' . $orderId;
        if ((int) ($db->row('SELECT GET_LOCK(?,10) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
            throw new WalletException('concurrency_conflict', 'Order wallet recovery is already running.');
        }
        try {
            $current = wc_get_order($orderId);
            if (!$current instanceof \WC_Order || !in_array($current->get_status(), ['failed', 'cancelled'], true)) {
                return;
            }
            $allocation = $this->allocation($orderId);
            if ($allocation === null || (int) $allocation['external_amount'] !== 0 || $allocation['hold_id'] === null) {
                return;
            }
            $hold = $this->services->holds->hold($allocation['hold_id']);
            if ($hold['state'] === 'held') {
                $this->services->holds->release($hold['id'], 'wc:release:' . $allocation['id'], 0);
            } elseif ($hold['state'] === 'captured' && (int) $hold['refunded'] < (int) $hold['captured']) {
                $order = wc_get_order($orderId);
                if ($order instanceof \WC_Order) {
                    $currency = Currency::of($allocation['currency']);
                    $amount = new Money((int) $hold['captured'] - (int) $hold['refunded'], $currency);
                    $refund = wc_create_refund(['order_id' => $orderId, 'amount' => $amount->decimal(), 'reason' => __('Wallet restoration after order cancellation or failure', 'wallet-platform'), 'refund_payment' => true]);
                    if (is_wp_error($refund)) {
                        throw new WalletException('refund_failed', 'Order compensation requires review.');
                    }
                }
            }
        } catch (\Throwable $error) {
            wc_get_logger()->error('Wallet release failed: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => $orderId]);
            throw $error;
        } finally {
            $db->row('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }

    public function recoverCapture(string $holdId): void
    {
        $allocation = $this->services->db->row('SELECT * FROM ' . $this->services->db->table('allocations') . ' WHERE hold_id=?', [$holdId]);
        if ($allocation === null || (int) $allocation['external_amount'] !== 0) {
            return;
        }
        $this->locked((int) $allocation['order_id'], function () use ($holdId): array {
            $this->recoverLocked($holdId);
            return [];
        });
    }

    private function recoverLocked(string $holdId): void
    {
        $db = $this->services->db;
        $allocation = $db->row('SELECT * FROM ' . $db->table('allocations') . ' WHERE hold_id=?', [$holdId]);
        if ($allocation === null) {
            return; // Non-WooCommerce integrations own their own post-capture saga.
        }
        $hold = $this->services->holds->hold($holdId);
        $order = wc_get_order((int) $allocation['order_id']);
        if (!$order instanceof \WC_Order || $hold['state'] !== 'captured') {
            throw new WalletException('order_recovery_failed', 'Captured order mapping requires review.');
        }
        if (in_array($order->get_status(), ['cancelled', 'failed'], true)) {
            $this->onFailure($order->get_id());
            return;
        }
        if ($order->is_paid() && ($order->get_payment_method() !== 'wallet_platform' || $order->get_transaction_id() !== 'wallet:' . $allocation['id'])) {
            throw new WalletException('allocation_conflict', 'Paid order has a different payment reference.');
        }
        if ($order->is_paid() || $order->get_status() === 'refunded') {
            return;
        }
        $account = $this->services->queries->account($allocation['account_id']);
        $gross = Money::fromDecimal((string) $order->get_total('edit'), Currency::of($order->get_currency()));
        if ($order->get_payment_method() !== 'wallet_platform' || $order->get_customer_id() !== (int) $account['user_id'] || $gross->minor !== (int) $allocation['gross'] || $order->get_currency() !== $allocation['currency']) {
            throw new WalletException('allocation_conflict', 'Captured order differs from its allocation; manual review required.');
        }
        $order->update_meta_data('_wallet_allocation_id', $allocation['id']);
        $order->update_meta_data('_wallet_hold_id', $holdId);
        $order->save();
        $order->payment_complete('wallet:' . $allocation['id']);
    }

    public function allocation(int $orderId): ?array
    {
        return $this->services->db->row('SELECT * FROM ' . $this->services->db->table('allocations') . ' WHERE order_id=? ORDER BY attempt DESC LIMIT 1', [$orderId]);
    }

    private function locked(int $orderId, callable $work): array
    {
        $db = $this->services->db;
        $lock = 'wallet_order_' . $orderId;
        if ((int) ($db->row('SELECT GET_LOCK(?,10) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
            throw new WalletException('concurrency_conflict', 'Order wallet operation is already running.');
        }
        try {
            return $work();
        } finally {
            $db->row('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }
}
