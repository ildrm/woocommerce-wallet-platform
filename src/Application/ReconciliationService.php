<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\Database;
use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Domain\Shared\Money;

final class ReconciliationService
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function account(string $id, bool $freeze = false): array
    {
        return $this->db->transaction(function () use ($id, $freeze): array {
            $account = $this->db->row('SELECT * FROM ' . $this->db->table('accounts') . ' WHERE id=?' . $this->db->lockSuffix(), [$id]) ?? throw new WalletException('account_not_found', 'Wallet does not exist.');
            $issues = [];
            $ledger = ['available' => 0, 'reserved' => 0, 'pending' => 0];
            foreach ($this->db->rows('SELECT bucket,SUM(CASE WHEN side=\'credit\' THEN amount ELSE -amount END) AS balance FROM ' . $this->db->table('lines') . ' WHERE account_id=? GROUP BY bucket', [$id]) as $row) {
                if (!array_key_exists($row['bucket'], $ledger)) {
                    $issues[] = ['type' => 'unknown_wallet_bucket'];
                }
                $ledger[$row['bucket']] = (int) $row['balance'];
            }
            foreach (['available', 'reserved', 'pending'] as $bucket) {
                if ($ledger[$bucket] !== (int) $account[$bucket] || $ledger[$bucket] < 0) {
                    $issues[] = ['type' => 'projection', 'bucket' => $bucket, 'projection_minor' => (string) $account[$bucket], 'ledger_minor' => (string) $ledger[$bucket]];
                }
            }
            foreach (['available', 'pending'] as $bucket) {
                $lots = $this->db->row('SELECT COALESCE(SUM(remaining),0) AS balance FROM ' . $this->db->table('lots') . ' WHERE account_id=? AND state=?', [$id, $bucket]);
                if ((int) ($lots['balance'] ?? 0) !== $ledger[$bucket]) {
                    $issues[] = ['type' => 'lots', 'bucket' => $bucket];
                }
            }
            $holds = $this->db->row('SELECT COALESCE(SUM(amount),0) AS balance FROM ' . $this->db->table('holds') . " WHERE account_id=? AND state='held'", [$id]);
            $consumed = $this->db->row('SELECT COALESCE(SUM(amount),0) AS balance FROM ' . $this->db->table('consumptions') . " WHERE account_id=? AND state='held'", [$id]);
            if ((int) ($holds['balance'] ?? 0) !== $ledger['reserved'] || (int) ($consumed['balance'] ?? 0) !== $ledger['reserved']) {
                $issues[] = ['type' => 'holds'];
            }
            $unbalanced = $this->db->rows('SELECT l.entry_id FROM ' . $this->db->table('lines') . ' l WHERE l.entry_id IN (SELECT x.entry_id FROM ' . $this->db->table('lines') . ' x WHERE x.account_id=?) GROUP BY l.entry_id HAVING SUM(CASE WHEN l.side=\'debit\' THEN l.amount ELSE -l.amount END)<>0 LIMIT 100', [$id]);
            foreach ($unbalanced as $entry) {
                $issues[] = ['type' => 'unbalanced_journal', 'entry_id' => $entry['entry_id']];
            }
            $orphan = $this->db->row('SELECT l.id FROM ' . $this->db->table('lines') . ' l LEFT JOIN ' . $this->db->table('entries') . ' e ON e.id=l.entry_id WHERE l.account_id=? AND (e.id IS NULL OR e.currency<>? OR e.exponent<>?) LIMIT 1', [$id, $account['currency'], (int) $account['exponent']]);
            if ($orphan !== null) {
                $issues[] = ['type' => 'orphan_or_currency'];
            }
            $invalidHold = $this->db->row('SELECT id FROM ' . $this->db->table('holds') . " WHERE account_id=? AND ((state='captured' AND captured<>amount) OR refunded>captured OR (state='held' AND captured<>0)) LIMIT 1", [$id]);
            if ($invalidHold !== null) {
                $issues[] = ['type' => 'hold_state', 'hold_id' => $invalidHold['id']];
            }
            $orphanLot = $this->db->row('SELECT c.id FROM ' . $this->db->table('consumptions') . ' c LEFT JOIN ' . $this->db->table('lots') . ' l ON l.id=c.lot_id WHERE c.account_id=? AND (l.id IS NULL OR l.account_id<>c.account_id) LIMIT 1', [$id]);
            if ($orphanLot !== null) {
                $issues[] = ['type' => 'consumption_provenance'];
            }
            $capturedMismatch = $this->db->row('SELECT h.id FROM ' . $this->db->table('holds') . ' h LEFT JOIN ' . $this->db->table('consumptions') . " c ON c.hold_id=h.id AND c.state='captured' WHERE h.account_id=? AND h.state='captured' GROUP BY h.id,h.captured,h.refunded HAVING COALESCE(SUM(c.amount),0)<>h.captured OR COALESCE(SUM(c.refunded),0)<>h.refunded LIMIT 1", [$id]);
            if ($capturedMismatch !== null) {
                $issues[] = ['type' => 'captured_provenance', 'hold_id' => $capturedMismatch['id']];
            }
            $issues = array_merge($issues, $this->paymentIssues($account));
            $frozen = false;
            if ($freeze && $issues !== [] && in_array($account['state'], ['active', 'pending'], true)) {
                $this->db->execute('UPDATE ' . $this->db->table('accounts') . " SET state='frozen',version=version+1 WHERE id=?", [$id]);
                $context = new CommandContext($this->db, $this->clock->now(), 'reconciliation:' . $id . ':' . $account['version'], 'reconciliation_freeze', 0, 'Automated freeze after ledger discrepancy', [$id => $account]);
                $context->audit($id, ['issues' => $issues]);
                $context->event('wallet.reconciliation_failed', ['account_id' => $id, 'issue_count' => count($issues)]);
                $frozen = true;
            }
            return ['account_id' => $id, 'healthy' => $issues === [], 'issues' => $issues, 'ledger_minor' => array_map('strval', $ledger), 'frozen' => $frozen];
        });
    }

    /** Verify local gross tender evidence; provider fees/payouts require separate reconciliation. */
    private function paymentIssues(array $account): array
    {
        $issues = [];
        $db = $this->db;
        $payments = $db->rows('SELECT p.*,a.account_id,a.currency,a.gross,a.wallet_amount,a.external_amount,a.hold_id FROM ' . $db->table('payments') . ' p JOIN ' . $db->table('allocations') . ' a ON a.id=p.allocation_id WHERE a.account_id=?', [$account['id']]);
        foreach ($payments as $payment) {
            $id = $payment['id'];
            if ($payment['provider'] !== 'paypal' || (int) $payment['owner_id'] !== (int) $account['user_id'] || $payment['currency'] !== $account['currency'] || min((int) $payment['wallet_amount'], (int) $payment['external_amount'], (int) $payment['gross']) <= 0 || max((int) $payment['wallet_amount'], (int) $payment['external_amount'], (int) $payment['gross']) > Money::MAX_MINOR || (int) $payment['gross'] !== (int) $payment['wallet_amount'] + (int) $payment['external_amount']) {
                $issues[] = ['type' => 'payment_allocation', 'payment_id' => $id];
            }
            $hold = $payment['hold_id'] === null ? null : $db->row('SELECT * FROM ' . $db->table('holds') . ' WHERE id=?', [$payment['hold_id']]);
            if ($payment['hold_id'] !== null && ($hold === null || $hold['account_id'] !== $account['id'] || $hold['currency'] !== $payment['currency'] || (int) $hold['amount'] !== (int) $payment['wallet_amount'])) {
                $issues[] = ['type' => 'payment_hold', 'payment_id' => $id];
            }
            if ((int) $payment['external_settled'] && ($payment['provider_order'] === null || $payment['capture_id'] === null || !$this->externalJournal('split:external:capture:' . $id, 'split_external_capture', $account, (int) $payment['external_amount'], false))) {
                $issues[] = ['type' => 'external_capture_journal', 'payment_id' => $id];
            }
            if ($payment['state'] === 'completed' && (!(int) $payment['external_settled'] || $hold === null || $hold['state'] !== 'captured' || (int) $hold['captured'] !== (int) $payment['wallet_amount'])) {
                $issues[] = ['type' => 'payment_completion', 'payment_id' => $id];
            }
            $totals = ['wallet_amount' => 0, 'external_amount' => 0, 'completed_wallet' => 0];
            foreach ($db->rows('SELECT * FROM ' . $db->table('payment_refunds') . ' WHERE payment_id=?', [$id]) as $refund) {
                if ((int) $refund['gross'] <= 0 || min((int) $refund['wallet_amount'], (int) $refund['external_amount']) < 0 || max((int) $refund['gross'], (int) $refund['wallet_amount'], (int) $refund['external_amount']) > Money::MAX_MINOR || (int) $refund['gross'] !== (int) $refund['wallet_amount'] + (int) $refund['external_amount']) {
                    $issues[] = ['type' => 'payment_refund_allocation', 'refund_id' => $refund['id']];
                }
                foreach (['wallet_amount', 'external_amount'] as $bucket) {
                    // Saturate corrupted aggregates above the business ceiling without integer overflow.
                    $totals[$bucket] = min(Money::MAX_MINOR + 1, $totals[$bucket] + min(Money::MAX_MINOR + 1, max(0, (int) $refund[$bucket])));
                }
                // External-settled is a valid intermediate state before the journal commits.
                if ($refund['state'] === 'completed') {
                    $totals['completed_wallet'] = min(Money::MAX_MINOR + 1, $totals['completed_wallet'] + min(Money::MAX_MINOR + 1, max(0, (int) $refund['wallet_amount'])));
                    if ((int) $refund['external_amount'] > 0 && ($refund['provider_refund'] === null || !$this->externalJournal('split:external:refund:' . $refund['id'], 'split_external_refund', $account, (int) $refund['external_amount'], true))) {
                        $issues[] = ['type' => 'external_refund_journal', 'refund_id' => $refund['id']];
                    }
                }
            }
            if ($totals['wallet_amount'] > (int) $payment['wallet_amount'] || $totals['external_amount'] > (int) $payment['external_amount'] || $totals['completed_wallet'] > (int) ($hold['refunded'] ?? 0)) {
                $issues[] = ['type' => 'payment_refund_ceiling', 'payment_id' => $id];
            }
        }
        return $issues;
    }

    private function externalJournal(string $key, string $operation, array $account, int $amount, bool $refund): bool
    {
        $entry = $this->db->row('SELECT * FROM ' . $this->db->table('entries') . ' WHERE reference=?', ['cmd:' . hash('sha256', $key)]);
        if ($entry === null || $entry['operation'] !== $operation || $entry['currency'] !== $account['currency'] || (int) $entry['exponent'] !== (int) $account['exponent']) {
            return false;
        }
        $lines = $this->db->rows('SELECT * FROM ' . $this->db->table('lines') . ' WHERE entry_id=?', [$entry['id']]);
        if (count($lines) !== 2) {
            return false;
        }
        $expected = $refund ? ['external_spending' => 'debit', 'paypal_clearing' => 'credit'] : ['paypal_clearing' => 'debit', 'external_spending' => 'credit'];
        foreach ($lines as $line) {
            if ($line['account_id'] !== null || ($expected[$line['bucket']] ?? null) !== $line['side'] || (int) $line['amount'] !== $amount) {
                return false;
            }
            unset($expected[$line['bucket']]);
        }
        return $expected === [];
    }
}
