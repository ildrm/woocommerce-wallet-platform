<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Admin;

use WalletPlatform\Application\CommandContext;
use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Presentation\Format;
use WalletPlatform\WooCommerce\Orders\TopUps;

final class FundingPage
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_submenu_page('woocommerce', __('Wallet funding', 'wallet-platform'), __('Wallet funding', 'wallet-platform'), 'wallet_view', 'wallet-platform-funding', [$this, 'render']));
        add_action('admin_post_wallet_confirm_funding', [$this, 'confirm']);
    }

    public function confirm(): void
    {
        if (!current_user_can('wallet_credit')) {
            wp_die(esc_html__('You cannot confirm wallet funding.', 'wallet-platform'), '', ['response' => 403]);
        }
        check_admin_referer('wallet_confirm_funding');
        $id = sanitize_text_field(wp_unslash($_POST['topup_id'] ?? ''));
        $reference = sanitize_text_field(wp_unslash($_POST['reference'] ?? ''));
        $reason = sanitize_textarea_field(wp_unslash($_POST['reason'] ?? ''));
        $key = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !preg_match('/^[A-Za-z0-9:_-]{3,120}$/D', $reference) || !preg_match('/^[a-f0-9-]{36}$/D', $key) || ($_POST['confirm'] ?? '') !== 'yes') {
            wp_die(esc_html__('Verify the funding reference and confirm receipt before continuing.', 'wallet-platform'));
        }
        $db = $this->services->db;
        $lock = 'wallet_topup_' . $id;
        if ((int) ($db->row('SELECT GET_LOCK(?,10) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
            wp_die(esc_html__('Another funding confirmation is running. Retry this form.', 'wallet-platform'));
        }
        try {
            $record = $this->services->topups->record($id);
            $order = wc_get_order((int) $record['order_id']);
            if (!$order instanceof \WC_Order || !in_array($order->get_payment_method(), ['bacs', 'cheque'], true) || !in_array($order->get_status(), ['pending', 'on-hold', 'processing', 'completed'], true) || (string) $order->get_meta('_wallet_topup_id') !== $id || ($order->get_transaction_id() !== '' && $order->get_transaction_id() !== $reference)) {
                throw new WalletException('funding_conflict', 'Offline order is not eligible for this receipt confirmation.');
            }
            $this->services->wallets->kernel->execute('funding:confirm:' . get_current_user_id() . ':' . $key, 'funding_confirmation', [$id, $reference], [$record['account_id']], get_current_user_id(), $reason, static function (CommandContext $context) use ($record, $reference): array {
                $context->audit($record['account_id'], ['topup_id' => $record['id'], 'external_reference' => $reference]);
                return ['reference' => $context->reference];
            });
            $order->payment_complete($reference);
            (new TopUps($this->services))->settled($order->get_id());
            if ($this->services->topups->record($id)['state'] !== 'completed') {
                throw new WalletException('funding_review', 'Receipt was recorded but wallet credit requires review.');
            }
        } catch (\Throwable $error) {
            wc_get_logger()->error('Offline funding confirmation failed: ' . get_class($error), ['source' => 'wallet-platform']);
            wp_die(esc_html__('Funding could not be confirmed. Review this order and retry the same reference before making another adjustment.', 'wallet-platform'), '', ['back_link' => true]);
        } finally {
            $db->row('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
        wp_safe_redirect(admin_url('admin.php?page=wallet-platform-funding&confirmed=1'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('wallet_view')) {
            return;
        }
        $after = sanitize_text_field(wp_unslash($_GET['after'] ?? ''));
        if ($after !== '' && !preg_match('/^[a-f0-9]{32}$/D', $after)) {
            $after = '';
        }
        $db = $this->services->db;
        $records = $db->rows('SELECT t.*,a.user_id,a.exponent FROM ' . $db->table('topups') . ' t JOIN ' . $db->table('accounts') . " a ON a.id=t.account_id WHERE t.id>? AND t.state IN ('created','pending_payment','refund_review') ORDER BY t.id LIMIT 50", [$after]);
        echo '<div class="wrap wallet-platform"><h1>' . esc_html__('Wallet funding requests', 'wallet-platform') . '</h1><p>' . esc_html__('Online funding is credited through its gateway settlement callback. Confirm a bank or cheque receipt only after independently verifying the funds reached the merchant.', 'wallet-platform') . '</p>';
        if (isset($_GET['confirmed'])) {
            echo '<div class="notice notice-success" role="status"><p>' . esc_html__('Funding receipt confirmed and wallet credit committed.', 'wallet-platform') . '</p></div>';
        }
        echo '<div class="wallet-table-scroll"><table class="widefat striped"><thead><tr>';
        foreach ([__('Funding reference', 'wallet-platform'), __('Customer', 'wallet-platform'), __('Order', 'wallet-platform'), __('Amount', 'wallet-platform'), __('Status', 'wallet-platform')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($records as $record) {
            echo '<tr><td><code>' . esc_html($record['id']) . '</code></td><td>' . esc_html((string) $record['user_id']) . '</td><td>' . esc_html((string) $record['order_id']) . '</td><td>' . esc_html(Format::money((int) $record['amount'], $record['currency'], (int) $record['exponent'])) . '</td><td>' . esc_html(Format::fundingState($record['state'])) . '</td></tr>';
        }
        if ($records === []) {
            echo '<tr><td colspan="5">' . esc_html__('No pending funding requests.', 'wallet-platform') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (count($records) === 50) {
            echo '<p><a href="' . esc_url(add_query_arg('after', end($records)['id'])) . '">' . esc_html__('Next funding requests', 'wallet-platform') . '</a></p>';
        }
        if (current_user_can('wallet_credit')) {
            echo '<h2>' . esc_html__('Confirm offline receipt', 'wallet-platform') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wallet_confirm_funding"><input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '">';
            wp_nonce_field('wallet_confirm_funding');
            foreach (['topup_id' => __('Funding reference from the table', 'wallet-platform'), 'reference' => __('Bank or cheque transaction reference', 'wallet-platform'), 'reason' => __('Reason and receipt verification notes', 'wallet-platform')] as $key => $label) {
                echo '<p><label for="wallet-funding-' . esc_attr($key) . '">' . esc_html($label) . '</label><input id="wallet-funding-' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="text" required></p>';
            }
            echo '<p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html__('I have verified the received funds, customer, amount and currency. This confirmation adds spendable wallet credit.', 'wallet-platform') . '</label></p><button class="button button-primary">' . esc_html__('Confirm receipt and add credit', 'wallet-platform') . '</button></form>';
        }
        echo '</div>';
    }
}
