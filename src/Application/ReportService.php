<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\Database;
use WalletPlatform\Domain\Shared\WalletException;

final class ReportService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Monetary fields remain decimal integer strings, including aggregated totals beyond Money's per-account bound. */
    public function liability(int $start, int $end): array
    {
        if ($start < 0 || $end <= $start) {
            throw new WalletException('invalid_period', 'Report requires a valid UTC period.');
        }
        $sql = 'SELECT e.currency,e.exponent,
            COALESCE(SUM(CASE WHEN e.created_at<? THEN CASE WHEN l.side=\'credit\' THEN l.amount ELSE -l.amount END ELSE 0 END),0) AS opening,
            COALESCE(SUM(CASE WHEN e.created_at>=? AND e.operation IN (\'credit\',\'topup_complete\',\'refund\') AND l.side=\'credit\' THEN l.amount ELSE 0 END),0) AS credits,
            COALESCE(SUM(CASE WHEN e.created_at>=? AND e.operation IN (\'debit\',\'capture\',\'topup_reverse\') AND l.side=\'debit\' THEN l.amount ELSE 0 END),0) AS debits,
            COALESCE(SUM(CASE WHEN e.created_at>=? AND e.operation IN (\'lot_lifecycle\',\'release\') THEN CASE WHEN l.side=\'debit\' THEN l.amount ELSE -l.amount END ELSE 0 END),0) AS expiration,
            COALESCE(SUM(CASE WHEN l.side=\'credit\' THEN l.amount ELSE -l.amount END),0) AS closing
            FROM ' . $this->db->table('entries') . ' e JOIN ' . $this->db->table('lines') . ' l ON l.entry_id=e.id WHERE l.account_id IS NOT NULL AND e.created_at<? GROUP BY e.currency,e.exponent ORDER BY e.currency';
        return array_map(static function (array $row): array {
            foreach (['opening', 'credits', 'debits', 'expiration', 'closing'] as $field) {
                $row[$field] = (string) $row[$field];
            }
            return $row;
        }, $this->db->rows($sql, [$start, $start, $start, $start, $end]));
    }
}
