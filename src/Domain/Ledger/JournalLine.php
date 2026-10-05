<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Ledger;

use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class JournalLine
{
    public function __construct(public readonly ?string $accountId, public readonly string $bucket, public readonly string $side, public readonly Money $money)
    {
        $money->positive();
        if (!in_array($side, ['debit', 'credit'], true) || ($accountId !== null && !in_array($bucket, ['available', 'reserved', 'pending'], true))) {
            throw new WalletException('invalid_journal_line', 'Invalid ledger direction or bucket.');
        }
        if (!preg_match('/^[a-z_]{1,40}$/D', $bucket)) {
            throw new WalletException('invalid_journal_line', 'Invalid ledger account name.');
        }
    }
}
