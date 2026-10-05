<?php

declare(strict_types=1);

namespace WalletPlatform\WordPress;

use WalletPlatform\Bootstrap\Services;
use WalletPlatform\Presentation\Format;

final class Privacy
{
    public function __construct(private readonly Services $services)
    {
    }

    public function register(): void
    {
        add_action('admin_init', static function (): void {
            wp_add_privacy_policy_content(__('Wallet Platform', 'wallet-platform'), wp_kses_post(__('We retain wallet account identifiers, currency, amounts, credit provenance, payment references and privileged-action audit records for financial integrity. No card data or telemetry is collected by this plugin. Financial records may require retention after account closure; contact the merchant about its retention policy.', 'wallet-platform')));
        });
        add_filter('wp_privacy_personal_data_exporters', function (array $exporters): array {
            $exporters['wallet-platform'] = ['exporter_friendly_name' => __('Wallet records', 'wallet-platform'), 'callback' => [$this, 'export']];
            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', function (array $erasers): array {
            $erasers['wallet-platform'] = ['eraser_friendly_name' => __('Wallet financial retention', 'wallet-platform'), 'callback' => [$this, 'erase']];
            return $erasers;
        });
    }

    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $page = max(1, min(1000000, $page));
        $db = $this->services->db;
        $offset = ($page - 1) * 100;
        $accounts = $db->rows('SELECT * FROM ' . $db->table('accounts') . ' WHERE user_id=? ORDER BY created_at,id LIMIT 100 OFFSET ?', [(int) $user->ID, $offset]);
        $transactions = $db->rows('SELECT e.id,e.operation,e.currency,e.exponent,e.created_at,l.account_id,SUM(CASE WHEN l.side=\'credit\' THEN l.amount ELSE -l.amount END) AS impact_minor FROM ' . $db->table('accounts') . ' a JOIN ' . $db->table('lines') . ' l ON l.account_id=a.id JOIN ' . $db->table('entries') . ' e ON e.id=l.entry_id WHERE a.user_id=? GROUP BY e.id,e.operation,e.currency,e.exponent,e.created_at,l.account_id ORDER BY e.created_at,e.id,l.account_id LIMIT 100 OFFSET ?', [(int) $user->ID, $offset]);
        $lots = $db->rows('SELECT l.* FROM ' . $db->table('lots') . ' l JOIN ' . $db->table('accounts') . ' a ON a.id=l.account_id WHERE a.user_id=? ORDER BY l.created_at,l.id LIMIT 100 OFFSET ?', [(int) $user->ID, $offset]);
        $payments = $db->rows('SELECT p.id,p.provider,p.provider_order,p.capture_id,p.state,p.created_at,a.order_id,a.currency,a.gross,a.wallet_amount,a.external_amount FROM ' . $db->table('payments') . ' p JOIN ' . $db->table('allocations') . ' a ON a.id=p.allocation_id WHERE p.owner_id=? ORDER BY p.created_at,p.id LIMIT 100 OFFSET ?', [(int) $user->ID, $offset]);
        $refunds = $db->rows('SELECT r.id,r.payment_id,r.gross,r.wallet_amount,r.external_amount,r.provider_refund,r.state,r.created_at FROM ' . $db->table('payment_refunds') . ' r JOIN ' . $db->table('payments') . ' p ON p.id=r.payment_id WHERE p.owner_id=? ORDER BY r.created_at,r.id LIMIT 100 OFFSET ?', [(int) $user->ID, $offset]);
        $data = [];
        foreach ($accounts as $account) {
            $balance = $this->services->queries->balance($account['id']);
            $data[] = $this->item('account-' . $account['id'], $balance);
        }
        foreach ($transactions as $transaction) {
            $transaction['balance_change'] = Format::money((int) $transaction['impact_minor'], $transaction['currency'], (int) $transaction['exponent']);
            unset($transaction['impact_minor']);
            $data[] = $this->item('entry-' . $transaction['id'] . '-' . $transaction['account_id'], $transaction);
        }
        foreach ($lots as $lot) {
            $data[] = $this->item('lot-' . $lot['id'], $lot);
        }
        foreach ($payments as $payment) {
            $data[] = $this->item('payment-' . $payment['id'], $payment);
        }
        foreach ($refunds as $refund) {
            $data[] = $this->item('payment-refund-' . $refund['id'], $refund);
        }
        return ['data' => $data, 'done' => count($accounts) < 100 && count($transactions) < 100 && count($lots) < 100 && count($payments) < 100 && count($refunds) < 100];
    }

    private function item(string $id, array $values): array
    {
        return ['group_id' => 'wallet-platform', 'group_label' => __('Wallet', 'wallet-platform'), 'item_id' => $id, 'data' => array_map(static fn ($name, $value): array => ['name' => (string) $name, 'value' => (string) $value], array_keys($values), array_values($values))];
    }

    public function erase(string $email): array
    {
        $user = get_user_by('email', $email);
        $retained = $user && $this->services->queries->accounts(1, '', (int) $user->ID) !== [];
        return ['items_removed' => false, 'items_retained' => (bool) $retained, 'messages' => $retained ? [__('Financial records were retained. The merchant must review its applicable retention obligations.', 'wallet-platform')] : [], 'done' => true];
    }
}
