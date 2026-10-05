<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Gateway;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\WooCommerce\Orders\PayPalOrders;

final class PayPalGateway extends \WC_Payment_Gateway
{
    public function __construct(private readonly Services $services, private readonly PayPalOrders $orders, private readonly bool $configured)
    {
        $this->id = 'wallet_platform_paypal';
        $this->method_title = __('Wallet + PayPal', 'wallet-platform');
        $this->method_description = __('Use eligible wallet credit and pay the remaining order amount with PayPal.', 'wallet-platform');
        $this->title = __('Wallet + PayPal', 'wallet-platform');
        $this->description = __('Your wallet credit is reserved while PayPal confirms the remaining amount. The order is paid after both payments are confirmed.', 'wallet-platform');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds'];
        $this->enabled = $configured ? 'yes' : 'no';
    }

    public function is_available(): bool
    {
        if (!$this->configured || !is_user_logged_in() || !is_ssl() || get_option('wallet_platform_checkout', 'yes') !== 'yes') {
            return false;
        }
        try {
            if (is_checkout_pay_page()) {
                $order = wc_get_order(absint(get_query_var('order-pay')));
                $payment = $order instanceof \WC_Order ? $this->orders->forOrder($order->get_id()) : null;
                if ($payment !== null && (int) $payment['owner_id'] === get_current_user_id() && !in_array($payment['state'], ['cancelled', 'compensated'], true)) {
                    return true;
                }
            }
            $currency = Currency::of(get_woocommerce_currency());
            $account = $this->services->wallets->account(get_current_user_id(), $currency);
            if ($account['state'] !== 'active' || !WC()->cart) {
                return false;
            }
            $available = Money::fromDecimal($this->services->queries->balance($account['id'])['available'], $currency);
            $total = Money::fromDecimal(wc_format_decimal(WC()->cart->get_total('edit'), $currency->exponent), $currency);
            return $available->minor > 0 && $available->minor < $total->minor && $this->orders->payments->supports($total->subtract($available));
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function payment_fields(): void
    {
        echo '<p>' . esc_html($this->description) . '</p>';
        try {
            $currency = Currency::of(get_woocommerce_currency());
            $account = $this->services->wallets->account(get_current_user_id(), $currency);
            $wallet = Money::fromDecimal($this->services->queries->balance($account['id'])['available'], $currency);
            $total = WC()->cart ? Money::fromDecimal(wc_format_decimal(WC()->cart->get_total('edit'), $currency->exponent), $currency) : null;
            if ($total !== null && $wallet->minor > 0 && $wallet->minor < $total->minor) {
                /* translators: 1: formatted wallet amount, 2: formatted PayPal amount. */
                echo '<p role="status">' . esc_html(sprintf(__('Wallet: %1$s. PayPal: %2$s.', 'wallet-platform'), $currency->code . ' ' . $wallet->decimal(), $currency->code . ' ' . $total->subtract($wallet)->decimal())) . '</p>';
                echo '<input type="hidden" name="wallet_paypal_amount" value="' . esc_attr($wallet->decimal()) . '"><input type="hidden" name="wallet_paypal_gross" value="' . esc_attr($total->decimal()) . '">';
            }
        } catch (\Throwable $error) {
            echo '<p>' . esc_html__('Review your wallet balance before paying.', 'wallet-platform') . '</p>';
        }
    }

    public function process_payment($order_id): array
    {
        if (!$this->configured) {
            return ['result' => 'failure'];
        }
        try {
            $order = wc_get_order($order_id);
            if (!$order instanceof \WC_Order) {
                throw new \RuntimeException('Order missing.');
            }
            $wallet = null;
            $gross = null;
            if ($this->orders->forOrder($order->get_id()) === null) {
                $currency = Currency::of($order->get_currency());
                $wallet = Money::fromDecimal((string) wp_unslash($_POST['wallet_paypal_amount'] ?? ''), $currency);
                $gross = Money::fromDecimal((string) wp_unslash($_POST['wallet_paypal_gross'] ?? ''), $currency);
            }
            $payment = $this->orders->start($order, $wallet, $gross);
            if (in_array($payment['state'], ['cancelled', 'compensated'], true)) {
                throw new \RuntimeException('Payment attempt cancelled.');
            }
            if ($payment['state'] === 'completed') {
                $this->orders->recover($payment['id']);
            }
            WC()->cart?->empty_cart();
            return ['result' => 'success', 'redirect' => $payment['approval_url'] ?? $this->get_return_url($order)];
        } catch (\Throwable $error) {
            wc_get_logger()->error('PayPal split checkout needs review: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => (int) $order_id]);
            wc_add_notice(__('Payment could not be confirmed. Check this order’s payment status before paying again.', 'wallet-platform'), 'error');
            return ['result' => 'failure'];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        return $this->configured ? $this->orders->gatewayRefund((int) $order_id, $amount) : new \WP_Error('wallet_paypal_disabled', __('PayPal wallet payments are disabled.', 'wallet-platform'));
    }
}
