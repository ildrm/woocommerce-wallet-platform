<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Domain\Wallet\WalletState;
use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Ledger\JournalLine;

final class TopUpService
{
    public function __construct(private readonly WalletService $wallets)
    {
    }

    public function create(string $accountId, Money $amount, string $key, int $actorId): array
    {
        return $this->wallets->kernel->execute($key, 'topup_create', [$accountId, $amount->jsonSerialize()], [$accountId], $actorId, 'Customer top-up request', function (CommandContext $context) use ($accountId, $amount, $actorId): array {
            $this->wallets->validateMoney($context, $accountId, $amount);
            WalletState::from($context->accounts[$accountId]['state'])->assertAllows('topup');
            if ((int) $context->accounts[$accountId]['user_id'] !== $actorId || $amount->minor > 10000000) {
                throw new WalletException('topup_rejected', 'Top-up ownership or amount limit rejected.');
            }
            $db = $context->db;
            $start = $context->now - $context->now % 86400;
            $usage = $db->row('SELECT COUNT(*) AS n,COALESCE(SUM(amount),0) AS amount FROM ' . $db->table('topups') . ' WHERE account_id=? AND created_at>=?', [$accountId, $start]);
            if ((int) ($usage['n'] ?? 0) >= 5 || $amount->minor > 50000000 - (int) ($usage['amount'] ?? 0)) {
                throw new WalletException('topup_limit', 'Daily top-up request limit reached.');
            }
            $id = CommandContext::id();
            $db->insert('topups', ['id' => $id, 'account_id' => $accountId, 'amount' => $amount->minor, 'currency' => $amount->currency->code, 'state' => 'created', 'created_at' => $context->now]);
            $context->audit($accountId, ['topup_id' => $id]);
            return ['topup_id' => $id];
        });
    }

    /** Adapter must verify provider settlement; record/order/amount binding is enforced again here. */
    public function complete(string $id, int $orderId, Money $amount): array
    {
        $record = $this->record($id);
        return $this->wallets->kernel->execute('topup:complete:' . $id, 'topup_complete', [$id, $orderId, $amount->jsonSerialize()], [$record['account_id']], 0, 'Settled top-up order #' . $orderId, function (CommandContext $context) use ($id, $orderId, $amount): array {
            $record = $this->record($id);
            if ((int) $record['order_id'] !== $orderId || (int) $record['amount'] !== $amount->minor || $record['currency'] !== $amount->currency->code || $record['state'] !== 'pending_payment') {
                throw new WalletException('topup_conflict', 'Top-up settlement differs from the immutable funding request.');
            }
            $result = $this->wallets->issue($context, $record['account_id'], $amount, 'topup');
            $context->db->execute('UPDATE ' . $context->db->table('topups') . " SET state='completed',lot_id=? WHERE id=?", [$result['lot_id'], $id]);
            $context->event('topup.completed', ['topup_id' => $id, 'order_id' => $orderId, 'account_id' => $record['account_id'], 'amount' => $amount->decimal()]);
            return $result;
        });
    }

    public function record(string $id): array
    {
        $db = $this->wallets->kernel->db;
        return $db->row('SELECT * FROM ' . $db->table('topups') . ' WHERE id=?', [$id]) ?? throw new WalletException('topup_not_found', 'Top-up does not exist.');
    }

    public function reverse(string $id, Money $amount, string $refundReference): array
    {
        $record = $this->record($id);
        return $this->wallets->kernel->execute('topup:reverse:' . $refundReference, 'topup_reverse', [$id, $amount->jsonSerialize()], [$record['account_id']], 0, 'Funding reversal: ' . substr($refundReference, 0, 150), function (CommandContext $context) use ($id, $amount): array {
            $record = $this->record($id);
            $accountId = $record['account_id'];
            $this->wallets->validateMoney($context, $accountId, $amount);
            if ($amount->minor > (int) $record['amount'] - (int) $record['refunded']) {
                throw new WalletException('refund_limit', 'Funding refund exceeds its original amount.');
            }
            $db = $context->db;
            $lot = $record['lot_id'] === null ? null : $db->row('SELECT * FROM ' . $db->table('lots') . ' WHERE id=?', [$record['lot_id']]);
            if ($lot === null || (int) $lot['remaining'] < $amount->minor || $lot['state'] !== 'available') {
                // Recovery cannot reopen a closed wallet or relax a suspension.
                $db->execute('UPDATE ' . $db->table('accounts') . " SET state='frozen',version=version+1 WHERE id=? AND state IN ('active','pending')", [$accountId]);
                $db->execute('UPDATE ' . $db->table('topups') . " SET state='refund_review' WHERE id=?", [$id]);
                $context->audit($accountId, ['topup_id' => $id, 'requested_reversal_minor' => (string) $amount->minor, 'requires_recovery' => true]);
                $context->event('topup.refund_review', ['account_id' => $accountId, 'topup_id' => $id]);
                return ['state' => 'refund_review', 'topup_id' => $id];
            }
            $db->execute('UPDATE ' . $db->table('lots') . ' SET remaining=remaining-? WHERE id=?', [$amount->minor, $lot['id']]);
            $db->insert('consumptions', ['id' => CommandContext::id(), 'account_id' => $accountId, 'lot_id' => $lot['id'], 'entry_id' => $context->entryId, 'amount' => $amount->minor, 'state' => 'captured']);
            $refunded = (int) $record['refunded'] + $amount->minor;
            $state = $refunded === (int) $record['amount'] ? 'refunded' : 'partially_refunded';
            $db->execute('UPDATE ' . $db->table('topups') . ' SET refunded=?,state=? WHERE id=?', [$refunded, $state, $id]);
            $context->post(new Journal($amount->currency, [new JournalLine($accountId, 'available', 'debit', $amount), new JournalLine(null, 'issuance', 'credit', $amount)]));
            $context->audit($accountId, ['topup_id' => $id, 'reversed_minor' => (string) $amount->minor]);
            $context->event('topup.refunded', ['account_id' => $accountId, 'topup_id' => $id, 'amount' => $amount->decimal()]);
            return ['state' => $state, 'topup_id' => $id];
        });
    }
}
