<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Gateway;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\WooCommerce\Orders\OrderPayments;

final class WalletGateway extends \WC_Payment_Gateway
{
    public function __construct(private readonly Services $services, private readonly OrderPayments $payments)
    {
        $this->id = 'wallet_platform';
        $this->method_title = __('Wallet', 'wallet-platform');
        $this->method_description = __('Pay the full order total using available store credit.', 'wallet-platform');
        $this->title = __('Wallet', 'wallet-platform');
        $this->description = __('The full order amount will be reserved and paid from your wallet.', 'wallet-platform');
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];
        $this->enabled = 'yes';
    }

    public function is_available(): bool
    {
        if (!is_user_logged_in() || get_option('wallet_platform_checkout', 'yes') !== 'yes') {
            return false;
        }
        try {
            $account = $this->services->wallets->account(get_current_user_id(), Currency::of(get_woocommerce_currency()));
            $balance = $this->services->queries->balance($account['id']);
            $available = Money::fromDecimal($balance['available'], new Currency($account['currency'], (int) $account['exponent']));
            if ($account['state'] !== 'active') {
                return false;
            }
            $cart = WC()->cart;
            if ($cart) {
                foreach ($cart->get_cart() as $item) {
                    if (!empty($item['wallet_topup'])) {
                        return false;
                    }
                }
                $total = Money::fromDecimal(wc_format_decimal($cart->get_total('edit'), (int) $account['exponent']), new Currency($account['currency'], (int) $account['exponent']));
                return $available->minor >= $total->minor && $total->minor > 0;
            }
            return $available->minor > 0;
        } catch (\Throwable $error) {
            return false;
        }
    }

    public function process_payment($order_id): array
    {
        try {
            $order = wc_get_order($order_id);
            if (!$order instanceof \WC_Order) {
                throw new \RuntimeException('Order missing.');
            }
            $this->payments->pay($order);
            WC()->cart?->empty_cart();
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        } catch (\Throwable $error) {
            wc_get_logger()->error('Wallet checkout failed: ' . get_class($error), ['source' => 'wallet-platform', 'order_id' => (int) $order_id]);
            wc_add_notice(__('Wallet payment could not be completed. Check your balance and retry this order; the same capture will be recovered.', 'wallet-platform'), 'error');
            return ['result' => 'failure'];
        }
    }

    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        return $this->payments->gatewayRefund((int) $order_id, $amount, (string) $reason);
    }
}
