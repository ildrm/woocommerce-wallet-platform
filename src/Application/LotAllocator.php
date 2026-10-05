<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Domain\Shared\WalletException;

final class LotAllocator
{
    /** Consumption order avoids losing the earliest-expiring value. Locks are inherited from account. */
    public function consume(CommandContext $context, string $accountId, int $amount, ?string $holdId = null, bool $transfer = false): array
    {
        $db = $context->db;
        $sql = 'SELECT * FROM ' . $db->table('lots') . " WHERE account_id=? AND state='available' AND remaining>0 AND available_at<=? AND (expires_at IS NULL OR expires_at>?)";
        if ($transfer) {
            $sql .= ' AND transferable=1';
        }
        $lots = $db->rows($sql . ' ORDER BY CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END,expires_at,created_at,id LIMIT 1000', [$accountId, $context->now, $context->now]);
        $parts = [];
        foreach ($lots as $lot) {
            $part = min($amount, (int) $lot['remaining']);
            $db->execute('UPDATE ' . $db->table('lots') . ' SET remaining=remaining-? WHERE id=? AND remaining>=?', [$part, $lot['id'], $part]);
            $db->insert('consumptions', ['id' => CommandContext::id(), 'account_id' => $accountId, 'lot_id' => $lot['id'], 'entry_id' => $context->entryId, 'hold_id' => $holdId, 'amount' => $part, 'state' => $holdId === null ? 'captured' : 'held']);
            $parts[] = ['lot' => $lot, 'amount' => $part];
            $amount -= $part;
            if ($amount === 0) {
                return $parts;
            }
        }
        throw new WalletException('insufficient_funds', 'Insufficient eligible value or excessive lot fragmentation.');
    }

    public function create(CommandContext $context, string $accountId, int $amount, string $source, int $availableAt, ?int $expiresAt, bool $withdrawable = false, bool $transferable = false, ?string $parentId = null): string
    {
        $id = CommandContext::id();
        $context->db->insert('lots', ['id' => $id, 'account_id' => $accountId, 'source' => $source, 'original' => $amount, 'remaining' => $amount, 'state' => $availableAt > $context->now ? 'pending' : 'available', 'available_at' => $availableAt, 'expires_at' => $expiresAt, 'withdrawable' => (int) $withdrawable, 'transferable' => (int) $transferable, 'origin' => $context->reference, 'parent_id' => $parentId, 'created_at' => $context->now]);
        return $id;
    }
}
