<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Orders;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class TopUps
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            wc_register_order_type('wallet_topup', [
                'label' => __('Wallet funding', 'wallet-platform'), 'public' => false, 'show_ui' => false,
                'capability_type' => 'shop_order', 'map_meta_cap' => true, 'supports' => false,
                'exclude_from_order_count' => true, 'exclude_from_order_views' => true,
                'exclude_from_order_reports' => true, 'exclude_from_order_sales_reports' => true,
                'class_name' => FundingOrder::class, 'add_order_meta_boxes' => false,
            ]);
        });
        add_action('admin_post_wallet_topup', [$this, 'request']);
        add_action('woocommerce_payment_complete', [$this, 'settled']);
        add_action('woocommerce_order_refunded', function (int $orderId, int $refundId): void {
            $order = wc_get_order($orderId);
            $refund = wc_get_order($refundId);
            if (!$order instanceof \WC_Order || !$refund instanceof \WC_Order_Refund || !$order->get_meta('_wallet_topup_id')) {
                return;
            }
            try {
                $currency = Currency::of($order->get_currency());
                $amount = Money::fromDecimal(wc_format_decimal($refund->get_amount(), $currency->exponent), $currency);
                if ($amount->minor > 0) {
                    $result = $this->services->topups->reverse((string) $order->get_meta('_wallet_topup_id'), $amount, 'wc:refund:' . $refundId);
                    if ($result['state'] === 'refund_review') {
                        $order->add_order_note(__('Funding value was already consumed. Wallet frozen; financial recovery review required.', 'wallet-platform'));
                    }
                }
            } catch (\Throwable $error) {
                wc_get_logger()->error('Funding reversal failed: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => $orderId]);
                $order->add_order_note(__('Funding refund requires wallet reconciliation.', 'wallet-platform'));
            }
        }, 10, 2);
        add_filter('woocommerce_available_payment_gateways', function (array $gateways): array {
            $orderId = absint(get_query_var('order-pay'));
            $order = $orderId ? wc_get_order($orderId) : false;
            if ($order instanceof \WC_Order && $order->get_meta('_wallet_topup_id')) {
                $allowed = $this->allowedGateways();
                foreach ($gateways as $id => $_) {
                    if ($id === 'wallet_platform' || !in_array($id, $allowed, true)) {
                        unset($gateways[$id]);
                    }
                }
            }
            return $gateways;
        });
    }

    public function request(): void
    {
        if (!is_user_logged_in() || get_option('wallet_platform_topups', 'no') !== 'yes' || $this->allowedGateways() === []) {
            wp_die(esc_html__('Funding is unavailable.', 'wallet-platform'), '', ['response' => 403]);
        }
        check_admin_referer('wallet_topup');
        try {
            $key = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
            if (!preg_match('/^[a-f0-9-]{36}$/D', $key)) {
                throw new WalletException('invalid_command', 'Invalid funding reference.');
            }
            $currency = Currency::of(get_woocommerce_currency());
            $amount = Money::fromDecimal(sanitize_text_field(wp_unslash($_POST['amount'] ?? '')), $currency);
            $account = $this->services->wallets->account(get_current_user_id(), $currency);
            $result = $this->services->topups->create($account['id'], $amount, 'topup:request:' . get_current_user_id() . ':' . $key, get_current_user_id());
            $order = $this->order($result['topup_id']);
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        } catch (WalletException $error) {
            wp_die(esc_html($error->getMessage()), '', ['back_link' => true]);
        } catch (\Throwable $error) {
            wc_get_logger()->error('Top-up creation failed: ' . get_class($error), ['source' => 'wallet-platform']);
            wp_die(esc_html__('Funding is temporarily unavailable. Retry with the original form reference.', 'wallet-platform'), '', ['back_link' => true]);
        }
    }

    public function order(string $topupId): \WC_Order
    {
        $db = $this->services->db;
        $lock = 'wallet_topup_' . $topupId;
        if ((int) ($db->row('SELECT GET_LOCK(?,10) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
            throw new WalletException('concurrency_conflict', 'Funding request is being processed.');
        }
        try {
            $record = $this->services->topups->record($topupId);
            $account = $this->services->queries->account($record['account_id']);
            if ((int) $account['user_id'] !== get_current_user_id()) {
                throw new WalletException('unauthorized_order', 'Funding request belongs to another account.');
            }
            if ($record['order_id'] !== null) {
                $order = wc_get_order((int) $record['order_id']);
                if (!$order instanceof \WC_Order) {
                    throw new WalletException('order_not_found', 'Funding order is missing; manual recovery required.');
                }
                return $order;
            }
            $money = new Money((int) $record['amount'], new Currency($record['currency'], (int) $account['exponent']));
            $order = new FundingOrder();
            $order->set_customer_id((int) $account['user_id']);
            $order->set_created_via('wallet_topup');
            $order->set_status('pending');
            // Non-taxable funding line: merchant liability, not discounted merchandise or sales revenue.
            $item = new \WC_Order_Item_Fee();
            $item->set_name(__('Wallet funding', 'wallet-platform'));
            $item->set_tax_status('none');
            $item->set_amount($money->decimal());
            $item->set_total($money->decimal());
            $order->add_item($item);
            $order->set_currency($money->currency->code);
            $order->update_meta_data('_wallet_topup_id', $topupId);
            $order->set_total($money->decimal());
            $order->save();
            $db->execute('UPDATE ' . $db->table('topups') . " SET order_id=?,state='pending_payment' WHERE id=? AND order_id IS NULL", [$order->get_id(), $topupId]);
            return $order;
        } finally {
            $db->row('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }

    public function settled(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order || !$order->get_meta('_wallet_topup_id')) {
            return;
        }
        try {
            if (!$order->is_paid() || !in_array($order->get_payment_method(), $this->allowedGateways(), true) || $order->get_payment_method() === 'wallet_platform' || $order->get_transaction_id() === '') {
                throw new WalletException('settlement_unverified', 'Funding requires an allowed settled gateway with a payment reference.');
            }
            if (in_array($order->get_payment_method(), ['bacs', 'cheque', 'cod'], true) && !current_user_can('wallet_credit')) {
                throw new WalletException('settlement_unverified', 'Offline funding requires a privileged bank-receipt confirmation.');
            }
            $record = $this->services->topups->record((string) $order->get_meta('_wallet_topup_id'));
            $account = $this->services->queries->account($record['account_id']);
            if ((int) $account['user_id'] !== $order->get_customer_id()) {
                throw new WalletException('topup_conflict', 'Funding owner differs from the order owner.');
            }
            $currency = new Currency($order->get_currency(), (int) $account['exponent']);
            $this->services->topups->complete($record['id'], $orderId, Money::fromDecimal((string) $order->get_total('edit'), $currency));
        } catch (\Throwable $error) {
            $order->add_order_note(__('Wallet funding was not credited. Settlement verification or wallet review is required; retry with this order reference.', 'wallet-platform'));
            wc_get_logger()->error('Funding settlement rejected: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => $orderId]);
        }
    }

    private function allowedGateways(): array
    {
        $gateways = get_option('wallet_platform_topup_gateways', []);
        return is_array($gateways) ? array_values(array_filter($gateways, static fn ($id): bool => is_string($id) && $id !== 'wallet_platform' && preg_match('/^[a-z0-9_-]{1,64}$/D', $id) === 1)) : [];
    }
}
