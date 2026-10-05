<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class PayPalMethod extends AbstractPaymentMethodType
{
    protected $name = 'wallet_platform_paypal';

    public function __construct(private readonly string $pluginFile, private readonly bool $configured)
    {
    }

    public function initialize(): void
    {
        $this->settings = [];
    }

    public function is_active(): bool
    {
        return $this->configured && is_ssl() && get_option('wallet_platform_checkout', 'yes') === 'yes';
    }

    public function get_payment_method_script_handles(): array
    {
        wp_register_script('wallet-platform-paypal-blocks', plugins_url('assets/frontend/paypal.min.js', $this->pluginFile), ['wc-blocks-registry', 'wc-settings', 'wc-blocks-data-store', 'wp-data', 'wp-element', 'wp-i18n', 'wp-api-fetch'], '0.1.0', true);
        wp_set_script_translations('wallet-platform-paypal-blocks', 'wallet-platform', dirname($this->pluginFile) . '/languages');
        return ['wallet-platform-paypal-blocks'];
    }

    public function get_payment_method_data(): array
    {
        return ['title' => __('Wallet + PayPal', 'wallet-platform'), 'description' => __('Wallet funds are reserved until PayPal confirms the remaining amount.', 'wallet-platform'), 'supports' => ['products'], 'currencies' => \WalletPlatform\Infrastructure\Integrations\PayPal\PayPalProvider::CURRENCIES, 'wholeUnitCurrencies' => \WalletPlatform\Infrastructure\Integrations\PayPal\PayPalProvider::WHOLE_UNIT_CURRENCIES];
    }
}
