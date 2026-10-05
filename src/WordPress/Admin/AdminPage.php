<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Admin;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Domain\Wallet\WalletState;
use WalletPlatform\Presentation\Format;

final class AdminPage
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_submenu_page('woocommerce', __('Wallet', 'wallet-platform'), __('Wallet', 'wallet-platform'), 'wallet_view', 'wallet-platform', [$this, 'render']));
        add_action('admin_post_wallet_adjust', [$this, 'adjust']);
    }

    public function adjust(): void
    {
        $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        if (!in_array($operation, ['credit', 'debit', 'state'], true) || !current_user_can($operation === 'state' ? 'wallet_freeze' : 'wallet_' . $operation)) {
            wp_die(esc_html__('You cannot perform this wallet action.', 'wallet-platform'), '', ['response' => 403]);
        }
        check_admin_referer('wallet_adjust');
        if (($_POST['confirm'] ?? '') !== 'yes') {
            wp_die(esc_html__('Confirm the financial action before continuing.', 'wallet-platform'));
        }
        $userId = absint($_POST['user_id'] ?? 0);
        if (!get_userdata($userId)) {
            wp_die(esc_html__('Choose an existing customer.', 'wallet-platform'));
        }
        try {
            $currency = Currency::of(get_woocommerce_currency());
            $account = $this->services->wallets->account($userId, $currency);
            $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
            $rawKey = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
            if (!preg_match('/^[a-f0-9-]{36}$/D', $rawKey)) {
                throw new WalletException('invalid_command', 'Invalid request reference.');
            }
            $key = 'admin:' . get_current_user_id() . ':' . $rawKey;
            if ($operation === 'state') {
                $state = WalletState::tryFrom(sanitize_key(wp_unslash($_POST['state'] ?? '')));
                if ($state === null) {
                    throw new WalletException('invalid_state', 'Invalid wallet state.');
                }
                $this->services->wallets->setState($account['id'], $state, $key, get_current_user_id(), $reason);
            } else {
                $amount = Money::fromDecimal(sanitize_text_field(wp_unslash($_POST['amount'] ?? '')), $currency);
                $this->services->wallets->$operation($account['id'], $amount, $key, get_current_user_id(), $reason);
            }
        } catch (WalletException $error) {
            wp_die(esc_html($error->getMessage()), esc_html__('Wallet action rejected', 'wallet-platform'), ['back_link' => true]);
        } catch (\Throwable $error) {
            wc_get_logger()->error('Admin wallet action failed: ' . get_class($error), ['source' => 'wallet-platform']);
            wp_die(esc_html__('Processing failed. Retry with the same form reference; do not create a second adjustment until its status is known.', 'wallet-platform'), '', ['back_link' => true]);
        }
        wp_safe_redirect(admin_url('admin.php?page=wallet-platform&success=1'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('wallet_view')) {
            return;
        }
        echo '<div class="wrap wallet-platform"><h1>' . esc_html__('Wallet', 'wallet-platform') . '</h1>';
        if (isset($_GET['success'])) {
            echo '<div class="notice notice-success" role="status"><p>' . esc_html__('Wallet action committed.', 'wallet-platform') . '</p></div>';
        }
        echo '<p>' . esc_html__('Closed-loop store credit. Wallet deposits are liabilities; they are not sales revenue.', 'wallet-platform') . '</p>';
        if (current_user_can('wallet_reports_view')) {
            echo '<h2>' . esc_html__('Outstanding liability by currency', 'wallet-platform') . '</h2><ul>';
            foreach ($this->services->queries->liability() as $row) {
                echo '<li>' . esc_html($row['accounts'] . ' ' . __('accounts', 'wallet-platform') . ' · ' . __('Available', 'wallet-platform') . ': ' . Format::aggregate((string) $row['available'], $row['currency'], (int) $row['exponent']) . ' · ' . __('Reserved', 'wallet-platform') . ': ' . Format::aggregate((string) $row['reserved'], $row['currency'], (int) $row['exponent']) . ' · ' . __('Pending', 'wallet-platform') . ': ' . Format::aggregate((string) $row['pending'], $row['currency'], (int) $row['exponent'])) . '</li>';
            }
            echo '</ul>';
        }
        echo '<h2>' . esc_html__('Wallet accounts', 'wallet-platform') . '</h2><form method="get"><input type="hidden" name="page" value="wallet-platform"><label for="wallet-owner">' . esc_html__('Filter by customer ID', 'wallet-platform') . '</label> <input id="wallet-owner" type="number" min="1" name="user_id" value="' . esc_attr((string) absint($_GET['user_id'] ?? 0)) . '"> <button class="button">' . esc_html__('Search', 'wallet-platform') . '</button></form>';
        $userId = absint($_GET['user_id'] ?? 0);
        $after = sanitize_text_field(wp_unslash($_GET['after'] ?? ''));
        $accounts = $this->services->queries->accounts(50, $after, $userId > 0 ? $userId : null);
        echo '<div class="wallet-table-scroll"><table class="widefat striped"><caption class="screen-reader-text">' . esc_html__('Wallet accounts', 'wallet-platform') . '</caption><thead><tr>';
        foreach ([__('Customer ID', 'wallet-platform'), __('Currency', 'wallet-platform'), __('Status', 'wallet-platform'), __('Available', 'wallet-platform'), __('Reserved', 'wallet-platform'), __('Pending', 'wallet-platform')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($accounts as $row) {
            echo '<tr><td>' . esc_html((string) $row['user_id']) . '</td><td>' . esc_html($row['currency']) . '</td><td>' . esc_html(Format::state($row['state'])) . '</td>';
            foreach (['available', 'reserved', 'pending'] as $bucket) {
                echo '<td>' . esc_html(Format::money((int) $row[$bucket], $row['currency'], (int) $row['exponent'])) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if (count($accounts) === 50) {
            echo '<p><a class="button" href="' . esc_url(add_query_arg(['after' => end($accounts)['id'], 'user_id' => $userId], admin_url('admin.php?page=wallet-platform'))) . '">' . esc_html__('Next accounts', 'wallet-platform') . '</a></p>';
        }
        $operations = [];
        foreach (['credit' => __('Add store credit', 'wallet-platform'), 'debit' => __('Debit available credit', 'wallet-platform'), 'state' => __('Change account status', 'wallet-platform')] as $key => $label) {
            if (current_user_can($key === 'state' ? 'wallet_freeze' : 'wallet_' . $key)) {
                $operations[$key] = $label;
            }
        }
        if ($operations !== []) {
            echo '<h2>' . esc_html__('Customer adjustment', 'wallet-platform') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wallet_adjust"><input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            wp_nonce_field('wallet_adjust');
            echo '<p><label for="wallet-operation">' . esc_html__('Action', 'wallet-platform') . '</label> <select id="wallet-operation" name="operation">';
            foreach ($operations as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></p><p><label for="wallet-user">' . esc_html__('Customer ID', 'wallet-platform') . '</label><input id="wallet-user" name="user_id" type="number" min="1" required></p><p><label for="wallet-amount">' . esc_html__('Amount for credit/debit', 'wallet-platform') . ' (' . esc_html(get_woocommerce_currency()) . ')</label><input id="wallet-amount" name="amount" type="text" inputmode="decimal"></p><p><label for="wallet-state">' . esc_html__('Status for status changes', 'wallet-platform') . '</label><select id="wallet-state" name="state">';
            foreach (['active', 'frozen', 'suspended', 'closed'] as $state) {
                echo '<option value="' . esc_attr($state) . '">' . esc_html(Format::state($state)) . '</option>';
            }
            echo '</select></p><p><label for="wallet-reason">' . esc_html__('Reason (visible to authorized staff)', 'wallet-platform') . '</label><textarea id="wallet-reason" name="reason" required maxlength="500"></textarea></p><p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html__('I have verified the customer, amount and action. Debits remove available credit; closed accounts cannot reopen.', 'wallet-platform') . '</label></p><button class="button button-primary">' . esc_html__('Confirm wallet action', 'wallet-platform') . '</button></form>';
        }
        if (current_user_can('wallet_reports_view')) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=wallet-platform-tools')) . '">' . esc_html__('Open diagnostics and reconciliation', 'wallet-platform') . '</a></p>';
        }
        echo '</div>';
    }
}
