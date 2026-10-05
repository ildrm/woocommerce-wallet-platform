<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Admin;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Infrastructure\Database\Schema;
use WalletPlatform\Infrastructure\Integrations\PayPal\Factory;

final class ToolsPage
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_submenu_page('woocommerce', __('Wallet tools', 'wallet-platform'), __('Wallet tools', 'wallet-platform'), 'wallet_reports_view', 'wallet-platform-tools', [$this, 'render']));
    }

    public function render(): void
    {
        if (!current_user_can('wallet_reports_view')) {
            return;
        }
        $db = $this->services->db;
        $queue = $db->rows('SELECT state,COUNT(*) AS count FROM ' . $db->table('outbox') . ' GROUP BY state');
        $paypal = 'disabled';
        try {
            $config = Factory::configuration();
            $paypal = $config->enabled ? $config->environment : 'disabled';
        } catch (\Throwable $error) {
            $paypal = 'configuration error';
        }
        $values = ['PHP' => PHP_VERSION, 'WordPress' => get_bloginfo('version'), 'WooCommerce' => WC_VERSION, __('Wallet schema', 'wallet-platform') => (string) Schema::VERSION, __('Order storage', 'wallet-platform') => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy', __('Scheduler', 'wallet-platform') => function_exists('as_schedule_recurring_action') ? 'Action Scheduler' : 'WP-Cron', 'PayPal' => $paypal];
        echo '<div class="wrap wallet-platform"><h1>' . esc_html__('Wallet diagnostics and reconciliation', 'wallet-platform') . '</h1><p>' . esc_html__('This development build has not passed all commercial release gates. Diagnostics exclude credentials and customer contact details.', 'wallet-platform') . '</p><dl>';
        foreach ($values as $label => $value) {
            echo '<dt><strong>' . esc_html($label) . '</strong></dt><dd>' . esc_html($value) . '</dd>';
        }
        echo '</dl><h2>' . esc_html__('Event delivery queue', 'wallet-platform') . '</h2><ul>';
        foreach ($queue as $row) {
            echo '<li>' . esc_html($row['state'] . ': ' . $row['count']) . '</li>';
        }
        echo '</ul><p>' . esc_html__('Failed deliveries need review in the WooCommerce wallet-platform log. A delivery failure does not undo a committed financial transaction.', 'wallet-platform') . '</p><h2>' . esc_html__('Check a batch of wallets', 'wallet-platform') . '</h2><p>' . esc_html__('This check reads the ledger, balances, lots and holds. It does not repair history. Scheduled checks freeze accounts with discrepancies.', 'wallet-platform') . '</p><form method="get"><input type="hidden" name="page" value="wallet-platform-tools"><input type="hidden" name="check" value="1">';
        wp_nonce_field('wallet_reconcile', '_wpnonce', false);
        echo '<label for="wallet-reconcile-after">' . esc_html__('Continue after account reference (optional)', 'wallet-platform') . '</label> <input id="wallet-reconcile-after" name="after" type="text" maxlength="32" pattern="[a-f0-9]{32}"><button class="button">' . esc_html__('Check next 20 accounts', 'wallet-platform') . '</button></form>';
        if (isset($_GET['check']) && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'wallet_reconcile')) {
            $after = sanitize_text_field(wp_unslash($_GET['after'] ?? ''));
            if ($after === '' || preg_match('/^[a-f0-9]{32}$/D', $after)) {
                $accounts = $this->services->queries->accounts(20, $after);
                echo '<ul>';
                foreach ($accounts as $account) {
                    $report = $this->services->reconciliation->account($account['id']);
                    echo '<li>' . esc_html($account['id'] . ': ' . ($report['healthy'] ? __('Balanced', 'wallet-platform') : __('Discrepancy — review required', 'wallet-platform'))) . '</li>';
                }
                echo '</ul>';
                if (count($accounts) === 20) {
                    echo '<p>' . esc_html__('Next cursor:', 'wallet-platform') . ' <code>' . esc_html(end($accounts)['id']) . '</code></p>';
                }
            }
        }
        echo '</div>';
    }
}
