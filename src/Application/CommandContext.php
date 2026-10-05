<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\Database;
use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class CommandContext
{
    public readonly string $entryId;

    public function __construct(public readonly Database $db, public readonly int $now, public readonly string $reference, public readonly string $operation, public readonly int $actorId, public readonly string $reason, public array $accounts)
    {
        $this->entryId = self::id();
    }

    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function post(Journal $journal): void
    {
        $this->db->insert('entries', ['id' => $this->entryId, 'reference' => $this->reference, 'operation' => $this->operation, 'currency' => $journal->currency->code, 'exponent' => $journal->currency->exponent, 'actor_id' => $this->actorId, 'reason' => $this->reason, 'created_at' => $this->now]);
        $deltas = [];
        foreach ($journal->lines as $line) {
            $this->db->insert('lines', ['id' => self::id(), 'entry_id' => $this->entryId, 'account_id' => $line->accountId, 'bucket' => $line->bucket, 'side' => $line->side, 'amount' => $line->money->minor]);
            if ($line->accountId !== null) {
                if (!isset($this->accounts[$line->accountId]) || $this->accounts[$line->accountId]['currency'] !== $journal->currency->code || (int) $this->accounts[$line->accountId]['exponent'] !== $journal->currency->exponent) {
                    throw new WalletException('unlocked_account', 'Journal account must be locked and currency-compatible.');
                }
                $deltas[$line->accountId][$line->bucket] = ($deltas[$line->accountId][$line->bucket] ?? 0) + ($line->side === 'credit' ? $line->money->minor : -$line->money->minor);
            }
        }
        foreach ($deltas as $id => $buckets) {
            foreach ($buckets as $bucket => $delta) {
                $amount = new Money((int) $this->accounts[$id][$bucket] + $delta, $journal->currency);
                if ($amount->minor < 0) {
                    throw new WalletException('insufficient_funds', 'Wallet has insufficient eligible funds.');
                }
                $this->accounts[$id][$bucket] = $amount->minor;
            }
            $account = $this->accounts[$id];
            $this->db->execute('UPDATE ' . $this->db->table('accounts') . ' SET available=?,reserved=?,pending=?,version=version+1 WHERE id=?', [(int) $account['available'], (int) $account['reserved'], (int) $account['pending'], $id]);
        }
    }

    public function audit(?string $accountId, array $context = []): void
    {
        $this->db->insert('audit', ['id' => self::id(), 'account_id' => $accountId, 'actor_id' => $this->actorId, 'action' => $this->operation, 'reference' => $this->reference, 'reason' => $this->reason, 'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => $this->now]);
    }

    public function event(string $name, array $payload): void
    {
        $this->db->insert('outbox', ['id' => self::id(), 'event' => $name, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'state' => 'pending', 'next_at' => $this->now, 'created_at' => $this->now]);
    }
}
