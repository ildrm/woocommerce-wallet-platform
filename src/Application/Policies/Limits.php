<?php

declare(strict_types=1);

namespace WalletPlatform\Application\Policies;

use WalletPlatform\Application\CommandContext;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class Limits
{
    public function __construct(public readonly int $transactionMaximum = 10000000, public readonly int $balanceMaximum = 100000000, public readonly int $dailySpendMaximum = 50000000)
    {
        foreach ([$transactionMaximum, $balanceMaximum, $dailySpendMaximum] as $limit) {
            if ($limit <= 0 || $limit > Money::MAX_MINOR) {
                throw new WalletException('invalid_limit', 'Limits must be positive bounded minor-unit amounts.');
            }
        }
    }

    public function check(CommandContext $context, string $id, Money $money, bool $credit): void
    {
        $money->positive();
        if ($money->minor > $this->transactionMaximum) {
            throw new WalletException('transaction_limit', 'Transaction exceeds configured limit.');
        }
        $account = $context->accounts[$id];
        if ($credit) {
            $total = (int) $account['available'] + (int) $account['reserved'] + (int) $account['pending'];
            if ($money->minor > $this->balanceMaximum - $total) {
                throw new WalletException('balance_limit', 'Credit exceeds configured balance limit.');
            }
            return;
        }
        $start = $context->now - ($context->now % 86400);
        $db = $context->db;
        $row = $db->row('SELECT COALESCE(SUM(l.amount),0) AS amount FROM ' . $db->table('lines') . ' l JOIN ' . $db->table('entries') . " e ON e.id=l.entry_id WHERE l.account_id=? AND l.bucket='available' AND l.side='debit' AND e.operation IN ('debit','reserve','transfer') AND e.created_at>=?", [$id, $start]);
        if ($money->minor > $this->dailySpendMaximum - (int) ($row['amount'] ?? 0)) {
            throw new WalletException('daily_limit', 'Daily spending limit reached.');
        }
    }
}
