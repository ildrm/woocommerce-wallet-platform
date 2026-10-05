<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\Database;
use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class QueryService
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function balance(string $id): array
    {
        $account = $this->db->row('SELECT * FROM ' . $this->db->table('accounts') . ' WHERE id=?', [$id]) ?? throw new WalletException('account_not_found', 'Wallet account does not exist.');
        $currency = new Currency($account['currency'], (int) $account['exponent']);
        $result = ['account_id' => $id, 'currency' => $currency->code, 'state' => $account['state']];
        foreach (['available', 'reserved', 'pending'] as $bucket) {
            $result[$bucket] = (new Money((int) $account[$bucket], $currency))->decimal();
        }
        $lots = $this->db->row('SELECT COALESCE(SUM(remaining),0) AS spendable,COALESCE(SUM(CASE WHEN source IN (\'promotion\',\'cashback\',\'reward\',\'voucher\',\'gift\') THEN remaining ELSE 0 END),0) AS promotional,COALESCE(SUM(CASE WHEN withdrawable=1 THEN remaining ELSE 0 END),0) AS withdrawable FROM ' . $this->db->table('lots') . " WHERE account_id=? AND state='available' AND available_at<=? AND (expires_at IS NULL OR expires_at>?)", [$id, $this->clock->now(), $this->clock->now()]);
        $result['available'] = (new Money((int) ($lots['spendable'] ?? 0), $currency))->decimal();
        $result['promotional'] = (new Money((int) ($lots['promotional'] ?? 0), $currency))->decimal();
        $result['withdrawable'] = (new Money((int) ($lots['withdrawable'] ?? 0), $currency))->decimal();
        return $result;
    }

    public function account(string $id): array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('accounts') . ' WHERE id=?', [$id]) ?? throw new WalletException('account_not_found', 'Wallet account does not exist.');
    }

    public function accounts(int $limit = 50, string $after = '', ?int $userId = null): array
    {
        $limit = max(1, min(100, $limit));
        $where = ' WHERE id>?';
        $parameters = [$after];
        if ($userId !== null) {
            $where .= ' AND user_id=?';
            $parameters[] = $userId;
        }
        $parameters[] = $limit;
        return $this->db->rows('SELECT * FROM ' . $this->db->table('accounts') . $where . ' ORDER BY id LIMIT ?', $parameters);
    }

    public function transactions(string $accountId, int $limit = 50, int $beforeTime = PHP_INT_MAX, string $beforeId = 'ffffffffffffffffffffffffffffffff'): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->rows('SELECT e.id,e.reference,e.operation,e.currency,e.exponent,e.reason,e.created_at,SUM(CASE WHEN l.side=\'credit\' THEN l.amount ELSE -l.amount END) AS impact_minor FROM ' . $this->db->table('entries') . ' e JOIN ' . $this->db->table('lines') . ' l ON l.entry_id=e.id WHERE l.account_id=? AND (e.created_at<? OR (e.created_at=? AND e.id<?)) GROUP BY e.id,e.reference,e.operation,e.currency,e.exponent,e.reason,e.created_at ORDER BY e.created_at DESC,e.id DESC LIMIT ?', [$accountId, $beforeTime, $beforeTime, $beforeId, $limit]);
    }

    public function liability(): array
    {
        return $this->db->rows('SELECT currency,exponent,COUNT(*) AS accounts,SUM(available) AS available,SUM(reserved) AS reserved,SUM(pending) AS pending FROM ' . $this->db->table('accounts') . ' GROUP BY currency,exponent');
    }

    public function expiring(string $accountId): array
    {
        return $this->db->rows('SELECT expires_at,SUM(remaining) AS remaining FROM ' . $this->db->table('lots') . " WHERE account_id=? AND remaining>0 AND state IN ('available','pending') AND expires_at>? GROUP BY expires_at ORDER BY expires_at LIMIT 5", [$accountId, $this->clock->now()]);
    }
}
