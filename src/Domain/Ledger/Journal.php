<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Ledger;

use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class Journal
{
    /** @param list<JournalLine> $lines */
    public function __construct(public readonly Currency $currency, public readonly array $lines)
    {
        if (count($lines) < 2 || count($lines) > 1000) {
            throw new WalletException('invalid_journal', 'Journal needs a bounded collection of lines.');
        }
        $debits = new Money(0, $currency);
        $credits = new Money(0, $currency);
        foreach ($lines as $line) {
            if ($line->side === 'debit') {
                $debits = $debits->add($line->money);
            } else {
                $credits = $credits->add($line->money);
            }
        }
        if ($debits->compare($credits) !== 0) {
            throw new WalletException('unbalanced_journal', 'Journal debits must equal credits.');
        }
    }
}
