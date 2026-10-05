<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress\Admin;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Presentation\Format;
use WalletPlatform\WooCommerce\Orders\PayPalOrders;

final class PaymentsPage
{
    public function __construct(private readonly Services $services, private readonly ?PayPalOrders $orders)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', fn () => add_submenu_page('woocommerce', __('Wallet payments', 'wallet-platform'), __('Wallet payments', 'wallet-platform'), 'wallet_view_transactions', 'wallet-platform-payments', [$this, 'render']));
        add_action('admin_post_wallet_payment_recovery', [$this, 'recover']);
    }

    public function recover(): void
    {
        if (!current_user_can('wallet_credit') || $this->orders === null) {
            wp_die(esc_html__('Payment recovery requires wallet credit permission and an enabled PayPal adapter.', 'wallet-platform'), '', ['response' => 403]);
        }
        check_admin_referer('wallet_payment_recovery');
        $id = sanitize_text_field(wp_unslash($_POST['reference'] ?? ''));
        $kind = sanitize_text_field(wp_unslash($_POST['kind'] ?? ''));
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !in_array($kind, ['payment', 'refund'], true) || ($_POST['confirm'] ?? '') !== 'yes') {
            wp_die(esc_html__('Select the original reference and confirm the recovery action.', 'wallet-platform'), '', ['response' => 400]);
        }
        try {
            $payment = $kind === 'payment' ? $this->orders->payments->record($id) : $this->orders->payments->record($this->orders->payments->refundRecord($id)['payment_id']);
            $this->services->wallets->kernel->execute('recovery:staff:' . get_current_user_id() . ':' . $kind . ':' . $id, 'staff_payment_recovery', [$kind, $id], [$payment['account_id']], get_current_user_id(), 'Inspect original provider resource and recover its existing payment', static function (\WalletPlatform\Application\CommandContext $context) use ($payment, $kind, $id): array {
                $context->audit($payment['account_id'], ['payment_id' => $payment['id'], 'kind' => $kind, 'reference' => $id]);
                return ['reference' => $id];
            });
            if ($kind === 'payment') {
                $this->orders->recover($id);
            } else {
                $this->orders->recoverRefund($id);
            }
        } catch (\Throwable $error) {
            wc_get_logger()->error('Staff payment recovery needs review: ' . get_class($error), ['source' => 'wallet-platform']);
            wp_die(esc_html__('Recovery could not be confirmed. The original reference remains for review; no replacement charge or refund was requested.', 'wallet-platform'), '', ['back_link' => true]);
        }
        wp_safe_redirect(admin_url('admin.php?page=wallet-platform-payments&inspected=1'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('wallet_view_transactions')) {
            return;
        }
        $after = sanitize_text_field(wp_unslash($_GET['after'] ?? ''));
        if ($after !== '' && !preg_match('/^[a-f0-9]{32}$/D', $after)) {
            $after = '';
        }
        $db = $this->services->db;
        $rows = $db->rows('SELECT p.*,a.order_id,a.currency,a.gross,a.wallet_amount,a.external_amount,w.exponent FROM ' . $db->table('payments') . ' p JOIN ' . $db->table('allocations') . ' a ON a.id=p.allocation_id JOIN ' . $db->table('accounts') . ' w ON w.id=a.account_id WHERE p.id>? ORDER BY p.id LIMIT 50', [$after]);
        echo '<div class="wrap wallet-platform"><h1>' . esc_html__('Wallet and PayPal payments', 'wallet-platform') . '</h1><p>' . esc_html__('Amounts preserve the original merchandise total. Pending or unknown provider responses require their original references. Do not replace a charge or refund while its outcome is unconfirmed.', 'wallet-platform') . '</p>';
        if ($this->orders === null) {
            echo '<p>' . esc_html__('PayPal is disabled. Financial records remain readable; provider recovery becomes available when the server adapter is enabled.', 'wallet-platform') . '</p>';
        }
        if (isset($_GET['inspected'])) {
            echo '<div class="notice notice-success" role="status"><p>' . esc_html__('Original payment reference inspected. Review its current status below.', 'wallet-platform') . '</p></div>';
        }
        echo '<div class="wallet-table-scroll"><table class="widefat striped"><thead><tr>';
        foreach ([__('Reference', 'wallet-platform'), __('Order', 'wallet-platform'), __('Customer', 'wallet-platform'), __('Original total', 'wallet-platform'), __('Wallet', 'wallet-platform'), __('PayPal', 'wallet-platform'), __('Status', 'wallet-platform'), __('Provider order / capture', 'wallet-platform')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><code>' . esc_html($row['id']) . '</code></td><td>' . esc_html((string) $row['order_id']) . '</td><td>' . esc_html((string) $row['owner_id']) . '</td>';
            foreach (['gross', 'wallet_amount', 'external_amount'] as $amount) {
                echo '<td>' . esc_html(Format::money((int) $row[$amount], $row['currency'], (int) $row['exponent'])) . '</td>';
            }
            echo '<td>' . esc_html(Format::paymentState($row['state'])) . '</td><td>' . esc_html(($row['provider_order'] ?? '—') . ' / ' . ($row['capture_id'] ?? '—')) . '</td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="8">' . esc_html__('No split-payment records.', 'wallet-platform') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if (count($rows) === 50) {
            echo '<p><a href="' . esc_url(add_query_arg('after', end($rows)['id'])) . '">' . esc_html__('Next payment records', 'wallet-platform') . '</a></p>';
        }
        $refunds = $db->rows('SELECT r.*,a.order_id,a.currency,w.exponent FROM ' . $db->table('payment_refunds') . ' r JOIN ' . $db->table('payments') . ' p ON p.id=r.payment_id JOIN ' . $db->table('allocations') . ' a ON a.id=p.allocation_id JOIN ' . $db->table('accounts') . " w ON w.id=a.account_id WHERE r.state<>'completed' ORDER BY r.created_at,r.id LIMIT 50");
        echo '<h2>' . esc_html__('Refunds awaiting confirmation or review', 'wallet-platform') . '</h2><ul>';
        foreach ($refunds as $refund) {
            /* translators: 1: order number, 2: status, 3: formatted wallet amount, 4: formatted PayPal amount. */
            echo '<li><code>' . esc_html($refund['id']) . '</code> · ' . esc_html(sprintf(__('Order #%1$s: %2$s. Wallet: %3$s. PayPal: %4$s.', 'wallet-platform'), $refund['order_id'], Format::paymentState($refund['state']), Format::money((int) $refund['wallet_amount'], $refund['currency'], (int) $refund['exponent']), Format::money((int) $refund['external_amount'], $refund['currency'], (int) $refund['exponent']))) . '</li>';
        }
        if ($refunds === []) {
            echo '<li>' . esc_html__('No pending refunds.', 'wallet-platform') . '</li>';
        }
        echo '</ul>';
        if ($this->orders !== null && current_user_can('wallet_credit')) {
            echo '<h2>' . esc_html__('Inspect and recover an original reference', 'wallet-platform') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wallet_payment_recovery">';
            wp_nonce_field('wallet_payment_recovery');
            echo '<p><label for="wallet-recovery-reference">' . esc_html__('Payment or refund reference from this page', 'wallet-platform') . '</label><input id="wallet-recovery-reference" name="reference" required pattern="[a-f0-9]{32}"></p><p><label for="wallet-recovery-kind">' . esc_html__('Reference type', 'wallet-platform') . '</label><select id="wallet-recovery-kind" name="kind"><option value="payment">' . esc_html__('Payment', 'wallet-platform') . '</option><option value="refund">' . esc_html__('Refund', 'wallet-platform') . '</option></select></p><p><label><input type="checkbox" name="confirm" value="yes" required> ' . esc_html__('Inspect the original provider reference. An approved payment may complete; a confirmed refund may restore wallet credit.', 'wallet-platform') . '</label></p><button class="button button-primary">' . esc_html__('Inspect and recover', 'wallet-platform') . '</button></form>';
        }
        echo '</div>';
    }
}
