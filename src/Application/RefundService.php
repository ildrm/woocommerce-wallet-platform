<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Ledger\JournalLine;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class RefundService
{
    public function __construct(private readonly WalletService $wallets, private readonly HoldService $holds, private readonly LotAllocator $lots)
    {
    }

    public function refund(string $holdId, Money $money, string $key, int $actorId, string $reason): array
    {
        $initial = $this->holds->hold($holdId);
        return $this->wallets->kernel->execute($key, 'refund', [$holdId, $money->jsonSerialize()], [$initial['account_id']], $actorId, $reason, function (CommandContext $context) use ($holdId, $money): array {
            $hold = $this->holds->hold($holdId);
            $id = $hold['account_id'];
            $this->wallets->validateMoney($context, $id, $money);
            if ($hold['state'] !== 'captured' || $money->minor > (int) $hold['captured'] - (int) $hold['refunded']) {
                throw new WalletException('refund_limit', 'Refund exceeds captured eligible value.');
            }
            $db = $context->db;
            $parts = $db->rows('SELECT c.id AS consumption_id,c.amount,c.refunded,l.* FROM ' . $db->table('consumptions') . ' c JOIN ' . $db->table('lots') . " l ON l.id=c.lot_id WHERE c.hold_id=? AND c.state='captured' AND c.refunded<c.amount ORDER BY l.created_at,l.id,c.id LIMIT 1000", [$holdId]);
            $remaining = $money->minor;
            $available = 0;
            $expired = 0;
            foreach ($parts as $part) {
                $amount = min($remaining, (int) $part['amount'] - (int) $part['refunded']);
                $db->execute('UPDATE ' . $db->table('consumptions') . ' SET refunded=refunded+? WHERE id=?', [$amount, $part['consumption_id']]);
                if ($part['expires_at'] !== null && (int) $part['expires_at'] <= $context->now) {
                    $expired += $amount;
                } else {
                    $available += $amount;
                    $this->lots->create($context, $id, $amount, $part['source'], $context->now, $part['expires_at'] === null ? null : (int) $part['expires_at'], (bool) $part['withdrawable'], (bool) $part['transferable'], $part['id']);
                }
                $remaining -= $amount;
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining !== 0) {
                throw new WalletException('ledger_mismatch', 'Refund provenance is inconsistent.');
            }
            $db->execute('UPDATE ' . $db->table('holds') . ' SET refunded=refunded+? WHERE id=?', [$money->minor, $holdId]);
            $lines = [new JournalLine(null, 'spending', 'debit', $money)];
            if ($available > 0) {
                $lines[] = new JournalLine($id, 'available', 'credit', new Money($available, $money->currency));
            }
            if ($expired > 0) {
                $lines[] = new JournalLine(null, 'expiration', 'credit', new Money($expired, $money->currency));
            }
            $context->post(new Journal($money->currency, $lines));
            $result = ['hold_id' => $holdId, 'account_id' => $id, 'reference' => $context->reference, 'amount' => $money->decimal(), 'restored_minor' => (string) $available, 'expired_minor' => (string) $expired];
            $context->audit($id, $result);
            $context->event('refund.processed', $result);
            return $result;
        });
    }
}
