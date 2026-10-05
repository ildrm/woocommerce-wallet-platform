<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Ledger\JournalLine;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class CreditLifecycle
{
    public function __construct(private readonly FinancialKernel $kernel, private readonly HoldService $holds)
    {
    }

    /** Bounded batch; stable per-lot and per-hold keys make retry safe. */
    public function run(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $db = $this->kernel->db;
        $now = $this->kernel->clock->now();
        $count = ['expired' => 0, 'matured' => 0, 'released' => 0];
        $lots = $db->rows('SELECT id,account_id,expires_at FROM ' . $db->table('lots') . " WHERE remaining>0 AND ((expires_at IS NOT NULL AND expires_at<=?) OR (state='pending' AND available_at<=?)) ORDER BY id LIMIT ?", [$now, $now, $limit]);
        foreach ($lots as $lot) {
            $stage = $lot['expires_at'] !== null && (int) $lot['expires_at'] <= $now ? 'expiry' : 'maturity';
            $result = $this->kernel->execute('lot:' . $stage . ':' . $lot['id'], 'lot_lifecycle', [$lot['id']], [$lot['account_id']], 0, 'Scheduled credit lifecycle', function (CommandContext $context) use ($lot): array {
                $db = $context->db;
                $current = $db->row('SELECT * FROM ' . $db->table('lots') . ' WHERE id=?', [$lot['id']]) ?? throw new WalletException('lot_not_found', 'Credit lot missing.');
                $amount = (int) $current['remaining'];
                if ($amount === 0) {
                    return ['action' => 'none'];
                }
                $id = $current['account_id'];
                $currency = new Currency($context->accounts[$id]['currency'], (int) $context->accounts[$id]['exponent']);
                $money = new Money($amount, $currency);
                if ($current['expires_at'] !== null && (int) $current['expires_at'] <= $context->now) {
                    $db->execute('UPDATE ' . $db->table('lots') . ' SET remaining=0 WHERE id=?', [$current['id']]);
                    $context->post(new Journal($currency, [new JournalLine($id, $current['state'], 'debit', $money), new JournalLine(null, 'expiration', 'credit', $money)]));
                    $action = 'expired';
                } elseif ($current['state'] === 'pending' && (int) $current['available_at'] <= $context->now) {
                    $db->execute('UPDATE ' . $db->table('lots') . " SET state='available' WHERE id=?", [$current['id']]);
                    $context->post(new Journal($currency, [new JournalLine($id, 'pending', 'debit', $money), new JournalLine($id, 'available', 'credit', $money)]));
                    $action = 'matured';
                } else {
                    return ['action' => 'none'];
                }
                $context->audit($id, ['lot_id' => $current['id'], 'action' => $action]);
                $context->event('credit.' . $action, ['account_id' => $id, 'lot_id' => $current['id'], 'amount' => $money->decimal()]);
                return ['action' => $action];
            });
            if (isset($count[$result['action']])) {
                ++$count[$result['action']];
            }
        }
        foreach ($db->rows('SELECT id FROM ' . $db->table('holds') . " WHERE state='held' AND expires_at<=? ORDER BY expires_at,id LIMIT ?", [$now, $limit]) as $hold) {
            $this->holds->release($hold['id'], 'hold:expiry:' . $hold['id'], 0);
            ++$count['released'];
        }
        return $count;
    }
}
