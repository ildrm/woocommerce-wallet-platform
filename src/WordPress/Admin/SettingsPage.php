<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Admin;

use WalletPlatform\Application\CommandContext;
use WalletPlatform\Bootstrap\Services;

final class SettingsPage
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_submenu_page('woocommerce', __('Wallet settings', 'wallet-platform'), __('Wallet settings', 'wallet-platform'), 'wallet_settings_manage', 'wallet-platform-settings', [$this, 'render']));
        add_action('admin_post_wallet_settings', [$this, 'save']);
    }

    public function save(): void
    {
        if (!current_user_can('wallet_settings_manage')) {
            wp_die(esc_html__('You cannot manage wallet settings.', 'wallet-platform'), '', ['response' => 403]);
        }
        check_admin_referer('wallet_settings');
        $gateways = array_values(array_filter(array_map('sanitize_key', (array) wp_unslash($_POST['gateways'] ?? [])), static fn (string $id): bool => $id !== 'wallet_platform' && isset(WC()->payment_gateways()->payment_gateways()[$id])));
        $desired = ['wallet_platform_checkout' => isset($_POST['checkout']) ? 'yes' : 'no', 'wallet_platform_topups' => isset($_POST['topups']) && $gateways !== [] ? 'yes' : 'no', 'wallet_platform_emails' => isset($_POST['emails']) ? 'yes' : 'no', 'wallet_platform_topup_gateways' => $gateways];
        try {
            $key = 'settings:' . get_current_user_id() . ':' . sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
            $this->services->wallets->kernel->execute($key, 'settings_requested', $desired, [], get_current_user_id(), 'Merchant wallet configuration', static function (CommandContext $context) use ($desired): array {
                $context->audit(null, ['requested' => $desired]);
                return ['reference' => $context->reference];
            });
            foreach ($desired as $name => $value) {
                update_option($name, $value, false);
                if (get_option($name) !== $value) {
                    throw new \RuntimeException('Settings persistence failed.');
                }
            }
        } catch (\Throwable $error) {
            wc_get_logger()->error('Wallet settings failure: ' . get_class($error), ['source' => 'wallet-platform']);
            wp_die(esc_html__('Some settings could not be saved. Review the current configuration before continuing.', 'wallet-platform'), '', ['back_link' => true]);
        }
        wp_safe_redirect(admin_url('admin.php?page=wallet-platform-settings&saved=1'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('wallet_settings_manage')) {
            return;
        }
        echo '<div class="wrap wallet-platform"><h1>' . esc_html__('Wallet setup', 'wallet-platform') . '</h1><p>' . esc_html__('Start with closed-loop store credit. Choose how customers can fund and spend their wallet, then verify a test purchase and refund before accepting real funds.', 'wallet-platform') . '</p>';
        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success" role="status"><p>' . esc_html__('Wallet settings saved.', 'wallet-platform') . '</p></div>';
        }
        echo '<p>' . esc_html__('Store currency:', 'wallet-platform') . ' ' . esc_html(get_woocommerce_currency()) . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wallet_settings"><input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
        wp_nonce_field('wallet_settings');
        foreach (['checkout' => [__('Allow full wallet payment at checkout', 'wallet-platform'), 'yes'], 'topups' => [__('Allow external top-ups', 'wallet-platform'), 'no'], 'emails' => [__('Send wallet transaction notifications', 'wallet-platform'), 'yes']] as $key => [$label, $default]) {
            echo '<p><label><input type="checkbox" name="' . esc_attr($key) . '" ' . checked(get_option('wallet_platform_' . $key, $default), 'yes', false) . '> ' . esc_html($label) . '</label></p>';
        }
        echo '<fieldset><legend><strong>' . esc_html__('Allowed funding gateways', 'wallet-platform') . '</strong></legend><p>' . esc_html__('Select only gateways you have verified. Wallet credit is added after confirmed payment with an external payment reference.', 'wallet-platform') . '</p>';
        $selected = (array) get_option('wallet_platform_topup_gateways', []);
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            if ($id !== 'wallet_platform') {
                echo '<p><label><input type="checkbox" name="gateways[]" value="' . esc_attr($id) . '" ' . checked(in_array($id, $selected, true), true, false) . '> ' . esc_html($gateway->get_method_title()) . '</label></p>';
            }
        }
        echo '</fieldset><p>' . esc_html__('Transfers, withdrawals, automatic card charges and split payments remain unavailable in this build. Deactivation preserves wallet history.', 'wallet-platform') . '</p><button class="button button-primary">' . esc_html__('Save wallet settings', 'wallet-platform') . '</button></form></div>';
    }
}
