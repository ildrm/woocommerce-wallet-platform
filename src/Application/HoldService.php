<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Policies\Limits;
use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Ledger\JournalLine;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Domain\Wallet\WalletState;

final class HoldService
{
    public function __construct(private readonly WalletService $wallets, private readonly LotAllocator $lots, private readonly Limits $limits)
    {
    }

    public function reserve(string $accountId, Money $money, string $reference, int $expiresAt, string $key, int $actorId): array
    {
        if ($reference === '' || strlen($reference) > 191) {
            throw new WalletException('invalid_reference', 'Hold requires a bounded business reference.');
        }
        return $this->wallets->kernel->execute($key, 'reserve', [$accountId, $money->jsonSerialize(), $reference, $expiresAt], [$accountId], $actorId, 'Reserve funds: ' . substr($reference, 0, 150), function (CommandContext $context) use ($accountId, $money, $reference, $expiresAt): array {
            $this->wallets->validateMoney($context, $accountId, $money);
            WalletState::from($context->accounts[$accountId]['state'])->assertAllows('reserve');
            $this->limits->check($context, $accountId, $money, false);
            if ($expiresAt <= $context->now || $expiresAt > $context->now + 604800) {
                throw new WalletException('invalid_expiry', 'Hold lifetime must be within seven days.');
            }
            $db = $context->db;
            if ($db->row('SELECT id FROM ' . $db->table('holds') . ' WHERE account_id=? AND reference=?', [$accountId, $reference]) !== null) {
                throw new WalletException('duplicate_reference', 'Business reference already has a hold.');
            }
            $holdId = CommandContext::id();
            $this->lots->consume($context, $accountId, $money->minor, $holdId);
            $db->insert('holds', ['id' => $holdId, 'account_id' => $accountId, 'amount' => $money->minor, 'currency' => $money->currency->code, 'reference' => $reference, 'state' => 'held', 'expires_at' => $expiresAt, 'created_at' => $context->now]);
            $context->post(new Journal($money->currency, [new JournalLine($accountId, 'available', 'debit', $money), new JournalLine($accountId, 'reserved', 'credit', $money)]));
            $result = ['hold_id' => $holdId, 'account_id' => $accountId, 'amount' => $money->decimal(), 'currency' => $money->currency->code, 'reference' => $context->reference, 'state' => 'held'];
            $context->audit($accountId, ['hold_id' => $holdId]);
            $context->event('funds.reserved', $result);
            return $result;
        });
    }

    public function capture(string $holdId, string $key, int $actorId): array
    {
        $initial = $this->hold($holdId);
        return $this->wallets->kernel->execute($key, 'capture', [$holdId], [$initial['account_id']], $actorId, 'Capture reserved funds', function (CommandContext $context) use ($holdId): array {
            $hold = $this->hold($holdId);
            $accountId = $hold['account_id'];
            if ($hold['state'] === 'captured') {
                return ['hold_id' => $holdId, 'state' => 'captured'];
            }
            if ($hold['state'] !== 'held' || (int) $hold['expires_at'] <= $context->now) {
                throw new WalletException('hold_unavailable', 'Hold is terminal or expired.');
            }
            WalletState::from($context->accounts[$accountId]['state'])->assertAllows('capture');
            $money = new Money((int) $hold['amount'], new Currency($hold['currency'], (int) $context->accounts[$accountId]['exponent']));
            $db = $context->db;
            $parts = $db->row('SELECT COALESCE(SUM(amount),0) AS amount FROM ' . $db->table('consumptions') . " WHERE hold_id=? AND account_id=? AND state='held'", [$holdId, $accountId]);
            if ((int) ($parts['amount'] ?? 0) !== $money->minor) {
                throw new WalletException('ledger_mismatch', 'Hold consumption is inconsistent.');
            }
            if ($db->row('SELECT c.id FROM ' . $db->table('consumptions') . ' c JOIN ' . $db->table('lots') . " l ON l.id=c.lot_id WHERE c.hold_id=? AND c.state='held' AND l.expires_at IS NOT NULL AND l.expires_at<=? LIMIT 1", [$holdId, $context->now]) !== null) {
                throw new WalletException('hold_unavailable', 'Reserved credit expired before capture.');
            }
            $db->execute('UPDATE ' . $db->table('holds') . " SET state='captured',captured=amount WHERE id=?", [$holdId]);
            $db->execute('UPDATE ' . $db->table('consumptions') . " SET state='captured' WHERE hold_id=? AND state='held'", [$holdId]);
            $context->post(new Journal($money->currency, [new JournalLine($accountId, 'reserved', 'debit', $money), new JournalLine(null, 'spending', 'credit', $money)]));
            $context->audit($accountId, ['hold_id' => $holdId]);
            $context->event('funds.captured', ['hold_id' => $holdId, 'account_id' => $accountId, 'amount' => $money->decimal()]);
            return ['hold_id' => $holdId, 'state' => 'captured'];
        });
    }

    public function release(string $holdId, string $key, int $actorId): array
    {
        $initial = $this->hold($holdId);
        return $this->wallets->kernel->execute($key, 'release', [$holdId], [$initial['account_id']], $actorId, 'Release reserved funds', function (CommandContext $context) use ($holdId): array {
            $hold = $this->hold($holdId);
            if (in_array($hold['state'], ['released', 'expired'], true)) {
                return ['hold_id' => $holdId, 'state' => $hold['state']];
            }
            if ($hold['state'] !== 'held') {
                throw new WalletException('hold_unavailable', 'Captured hold cannot be released.');
            }
            $db = $context->db;
            $available = 0;
            $expired = 0;
            $parts = $db->rows('SELECT c.*,l.expires_at FROM ' . $db->table('consumptions') . ' c JOIN ' . $db->table('lots') . " l ON l.id=c.lot_id WHERE c.hold_id=? AND c.state='held' LIMIT 1001", [$holdId]);
            if (count($parts) > 1000 || array_sum(array_column($parts, 'amount')) !== (int) $hold['amount']) {
                throw new WalletException('ledger_mismatch', 'Hold provenance is inconsistent.');
            }
            foreach ($parts as $part) {
                if ($part['expires_at'] !== null && (int) $part['expires_at'] <= $context->now) {
                    $expired += (int) $part['amount'];
                } else {
                    $available += (int) $part['amount'];
                    $db->execute('UPDATE ' . $db->table('lots') . ' SET remaining=remaining+? WHERE id=?', [(int) $part['amount'], $part['lot_id']]);
                }
            }
            $accountId = $hold['account_id'];
            $currency = new Currency($hold['currency'], (int) $context->accounts[$accountId]['exponent']);
            $lines = [new JournalLine($accountId, 'reserved', 'debit', new Money((int) $hold['amount'], $currency))];
            if ($available > 0) {
                $lines[] = new JournalLine($accountId, 'available', 'credit', new Money($available, $currency));
            }
            if ($expired > 0) {
                $lines[] = new JournalLine(null, 'expiration', 'credit', new Money($expired, $currency));
            }
            $state = (int) $hold['expires_at'] <= $context->now ? 'expired' : 'released';
            $db->execute('UPDATE ' . $db->table('holds') . ' SET state=? WHERE id=?', [$state, $holdId]);
            $db->execute('UPDATE ' . $db->table('consumptions') . " SET state='released' WHERE hold_id=? AND state='held'", [$holdId]);
            $context->post(new Journal($currency, $lines));
            $context->audit($accountId, ['hold_id' => $holdId, 'expired_minor' => (string) $expired]);
            $context->event('funds.released', ['hold_id' => $holdId, 'account_id' => $accountId, 'state' => $state]);
            return ['hold_id' => $holdId, 'state' => $state];
        });
    }

    public function hold(string $id): array
    {
        $db = $this->wallets->kernel->db;
        return $db->row('SELECT * FROM ' . $db->table('holds') . ' WHERE id=?', [$id]) ?? throw new WalletException('hold_not_found', 'Hold does not exist.');
    }
}
