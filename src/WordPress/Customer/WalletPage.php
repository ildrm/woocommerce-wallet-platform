<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Customer;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Presentation\Format;

final class WalletPage
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('init', static fn () => add_rewrite_endpoint('wallet', EP_ROOT | EP_PAGES));
        add_filter('woocommerce_account_menu_items', static function (array $items): array {
            $logout = $items['customer-logout'] ?? null;
            unset($items['customer-logout']);
            $items['wallet'] = __('Wallet', 'wallet-platform');
            if ($logout !== null) {
                $items['customer-logout'] = $logout;
            }
            return $items;
        });
        add_action('woocommerce_account_wallet_endpoint', [$this, 'render']);
    }

    public function render(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        try {
            $account = $this->services->wallets->account(get_current_user_id(), Currency::of(get_woocommerce_currency()));
            $balance = $this->services->queries->balance($account['id']);
            $beforeTime = isset($_GET['wallet_before_time']) ? absint($_GET['wallet_before_time']) : PHP_INT_MAX;
            $beforeId = sanitize_text_field(wp_unslash($_GET['wallet_before_id'] ?? 'ffffffffffffffffffffffffffffffff'));
            if (!preg_match('/^[a-f0-9]{32}$/D', $beforeId)) {
                $beforeId = 'ffffffffffffffffffffffffffffffff';
            }
            $rows = $this->services->queries->transactions($account['id'], 25, $beforeTime, $beforeId);
            $expiring = $this->services->queries->expiring($account['id']);
        } catch (\Throwable $error) {
            wc_print_notice(__('Your wallet is temporarily unavailable. Please try again later.', 'wallet-platform'), 'error');
            wc_get_logger()->error('Wallet page failure: ' . get_class($error), ['source' => 'wallet-platform']);
            return;
        }
        echo '<section class="wallet-platform" aria-labelledby="wallet-title"><h2 id="wallet-title">' . esc_html__('Your wallet', 'wallet-platform') . '</h2>';
        echo '<p>' . esc_html__('Store credit can be spent with this merchant. Pending and reserved funds are unavailable for new purchases.', 'wallet-platform') . '</p>';
        echo '<dl class="wallet-cards">';
        foreach (['available' => __('Available', 'wallet-platform'), 'reserved' => __('Reserved', 'wallet-platform'), 'pending' => __('Pending', 'wallet-platform'), 'promotional' => __('Promotional (included in available)', 'wallet-platform')] as $key => $label) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($balance['currency'] . ' ' . $balance[$key]) . '</dd></div>';
        }
        echo '</dl><p>' . esc_html__('Account status:', 'wallet-platform') . ' ' . esc_html(Format::state($balance['state'])) . '</p>';
        $this->pendingPayments($account);
        if ($expiring !== []) {
            echo '<h3>' . esc_html__('Upcoming credit expiration', 'wallet-platform') . '</h3><p>' . esc_html__('These amounts are already included in your available or pending credit. Expiry also applies while funds are reserved.', 'wallet-platform') . '</p><ul>';
            foreach ($expiring as $credit) {
                echo '<li>' . esc_html(Format::money((int) $credit['remaining'], $account['currency'], (int) $account['exponent']) . ' · ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $credit['expires_at'])) . '</li>';
            }
            echo '</ul>';
        }
        if (get_option('wallet_platform_topups', 'no') === 'yes' && $balance['state'] === 'active') {
            echo '<h3>' . esc_html__('Add funds', 'wallet-platform') . '</h3><p>' . esc_html__('The amount below will be charged through an external gateway. Wallet credit is added after confirmed payment.', 'wallet-platform') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wallet_topup">';
            wp_nonce_field('wallet_topup');
            echo '<input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '"><label for="wallet-topup-amount">' . esc_html__('Amount', 'wallet-platform') . ' (' . esc_html($balance['currency']) . ')</label><input id="wallet-topup-amount" name="amount" type="text" inputmode="decimal" required pattern="[0-9]+(\.[0-9]+)?"><button type="submit">' . esc_html__('Continue to payment', 'wallet-platform') . '</button></form>';
        }
        echo '<h3>' . esc_html__('Recent transactions', 'wallet-platform') . '</h3><div class="wallet-table-scroll" tabindex="0" role="region" aria-label="' . esc_attr__('Wallet transactions', 'wallet-platform') . '"><table><caption class="screen-reader-text">' . esc_html__('Wallet transaction history', 'wallet-platform') . '</caption><thead><tr><th scope="col">' . esc_html__('Date', 'wallet-platform') . '</th><th scope="col">' . esc_html__('Activity', 'wallet-platform') . '</th><th scope="col">' . esc_html__('Balance change', 'wallet-platform') . '</th></tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="3">' . esc_html__('No transactions yet.', 'wallet-platform') . '</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['created_at'])) . '</td><td>' . esc_html(Format::operation($row['operation'])) . '</td><td>' . esc_html(Format::money((int) $row['impact_minor'], $row['currency'], (int) $row['exponent'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (count($rows) === 25) {
            $last = end($rows);
            echo '<p><a href="' . esc_url(add_query_arg(['wallet_before_time' => $last['created_at'], 'wallet_before_id' => $last['id']], wc_get_account_endpoint_url('wallet'))) . '">' . esc_html__('Older transactions', 'wallet-platform') . '</a></p>';
        }
        echo '</section>';
    }

    private function pendingPayments(array $account): void
    {
        $db = $this->services->db;
        $payments = $db->rows('SELECT p.state,a.order_id,a.wallet_amount,a.external_amount FROM ' . $db->table('payments') . ' p JOIN ' . $db->table('allocations') . " a ON a.id=p.allocation_id WHERE p.owner_id=? AND a.account_id=? AND p.state NOT IN ('completed','cancelled','compensated') ORDER BY p.created_at DESC,p.id DESC LIMIT 10", [get_current_user_id(), $account['id']]);
        $refunds = $db->rows('SELECT r.state,r.wallet_amount,r.external_amount,a.order_id FROM ' . $db->table('payment_refunds') . ' r JOIN ' . $db->table('payments') . ' p ON p.id=r.payment_id JOIN ' . $db->table('allocations') . " a ON a.id=p.allocation_id WHERE p.owner_id=? AND a.account_id=? AND r.state<>'completed' ORDER BY r.created_at DESC,r.id DESC LIMIT 10", [get_current_user_id(), $account['id']]);
        foreach ([__('Payments awaiting confirmation', 'wallet-platform') => $payments, __('Refunds awaiting confirmation', 'wallet-platform') => $refunds] as $heading => $records) {
            if ($records === []) {
                continue;
            }
            echo '<h3>' . esc_html($heading) . '</h3><p>' . esc_html__('Review the existing order before making another payment or refund request. Wallet refund credit is added after the external refund is confirmed.', 'wallet-platform') . '</p><ul>';
            foreach ($records as $record) {
                $order = wc_get_order((int) $record['order_id']);
                /* translators: 1: order number, 2: status, 3: formatted wallet amount, 4: formatted PayPal amount. */
                echo '<li>' . esc_html(sprintf(__('Order #%1$s: %2$s. Wallet: %3$s. PayPal: %4$s.', 'wallet-platform'), $record['order_id'], Format::paymentState($record['state']), Format::money((int) $record['wallet_amount'], $account['currency'], (int) $account['exponent']), Format::money((int) $record['external_amount'], $account['currency'], (int) $account['exponent'])));
                if ($order instanceof \WC_Order && $order->get_customer_id() === get_current_user_id()) {
                    echo ' <a href="' . esc_url($order->get_view_order_url()) . '">' . esc_html__('Review order', 'wallet-platform') . '</a>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
    }
}
