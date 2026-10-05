<?php

declare(strict_types=1);

namespace WalletPlatform\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class PaymentMethod extends AbstractPaymentMethodType
{
    protected $name = 'wallet_platform';

    public function __construct(private readonly string $pluginFile)
    {
    }

    public function initialize(): void
    {
        $this->settings = [];
    }

    public function is_active(): bool
    {
        return get_option('wallet_platform_checkout', 'yes') === 'yes';
    }

    public function get_payment_method_script_handles(): array
    {
        wp_register_script('wallet-platform-blocks', plugins_url('assets/frontend/blocks.min.js', $this->pluginFile), ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n', 'wp-api-fetch'], '0.1.0', true);
        wp_set_script_translations('wallet-platform-blocks', 'wallet-platform', dirname($this->pluginFile) . '/languages');
        return ['wallet-platform-blocks'];
    }

    public function get_payment_method_data(): array
    {
        return ['title' => __('Wallet', 'wallet-platform'), 'description' => __('Pay the full total from available store credit.', 'wallet-platform'), 'supports' => ['products']];
    }
}
