<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Application\Port\Database;
use WalletPlatform\Domain\Shared\WalletException;

final class FinancialKernel
{
    public function __construct(public readonly Database $db, public readonly Clock $clock)
    {
    }

    /** @param list<string> $accountIds */
    public function execute(string $key, string $operation, array $payload, array $accountIds, int $actorId, string $reason, callable $work): array
    {
        if ($key === '' || strlen($key) > 191 || !preg_match('/^[\x21-\x7E]+$/D', $key) || $actorId < 0 || trim($reason) === '' || strlen($reason) > 500) {
            throw new WalletException('invalid_command', 'A stable key, actor and bounded reason are required.');
        }
        $keyHash = hash('sha256', $key);
        $payloadHash = hash('sha256', json_encode([$operation, $payload, $accountIds, $actorId, $reason], JSON_THROW_ON_ERROR));
        return $this->db->transaction(function () use ($keyHash, $payloadHash, $operation, $accountIds, $actorId, $reason, $work): array {
            $now = $this->clock->now();
            $this->db->insert('idempotency', ['key_hash' => $keyHash, 'operation' => $operation, 'payload_hash' => $payloadHash, 'created_at' => $now], true);
            $record = $this->db->row('SELECT * FROM ' . $this->db->table('idempotency') . ' WHERE key_hash=?' . $this->db->lockSuffix(), [$keyHash]);
            if ($record === null || !hash_equals((string) $record['payload_hash'], $payloadHash)) {
                throw new WalletException('idempotency_conflict', 'Idempotency key was used for a different command.');
            }
            if ($record['result'] !== null) {
                return json_decode((string) $record['result'], true, 32, JSON_THROW_ON_ERROR);
            }
            $ids = array_values(array_unique($accountIds));
            sort($ids, SORT_STRING);
            $accounts = [];
            foreach ($ids as $id) {
                $account = $this->db->row('SELECT * FROM ' . $this->db->table('accounts') . ' WHERE id=?' . $this->db->lockSuffix(), [$id]);
                if ($account === null) {
                    throw new WalletException('account_not_found', 'Wallet account does not exist.');
                }
                $accounts[$id] = $account;
            }
            $context = new CommandContext($this->db, $now, 'cmd:' . $keyHash, $operation, $actorId, $reason, $accounts);
            $result = $work($context);
            $this->db->execute('UPDATE ' . $this->db->table('idempotency') . ' SET result=? WHERE key_hash=?', [json_encode($result, JSON_THROW_ON_ERROR), $keyHash]);
            return $result;
        });
    }
}
