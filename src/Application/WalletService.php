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

final class WalletService
{
    public function __construct(public readonly FinancialKernel $kernel, private readonly LotAllocator $lots, private readonly Limits $limits)
    {
    }

    public function account(int $userId, Currency $currency): array
    {
        if ($userId <= 0) {
            throw new WalletException('invalid_owner', 'Wallet requires an authenticated positive owner ID.');
        }
        return $this->kernel->db->transaction(function () use ($userId, $currency): array {
            $db = $this->kernel->db;
            $created = $db->insert('accounts', ['id' => CommandContext::id(), 'user_id' => $userId, 'currency' => $currency->code, 'exponent' => $currency->exponent, 'state' => WalletState::Active->value, 'created_at' => $this->kernel->clock->now()], true);
            $account = $db->row('SELECT * FROM ' . $db->table('accounts') . ' WHERE user_id=? AND currency=?' . $db->lockSuffix(), [$userId, $currency->code]);
            if ($account === null || (int) $account['exponent'] !== $currency->exponent) {
                throw new WalletException('currency_mismatch', 'Stored account currency exponent differs.');
            }
            if ($created === 1) {
                $context = new CommandContext($db, $this->kernel->clock->now(), 'account:' . $account['id'], 'create', $userId, 'Wallet account created', [$account['id'] => $account]);
                $context->audit($account['id']);
                $context->event('wallet.created', ['account_id' => $account['id'], 'currency' => $currency->code]);
            }
            return $account;
        });
    }

    public function credit(string $accountId, Money $money, string $key, int $actorId, string $reason, string $source = 'admin_credit', ?int $availableAt = null, ?int $expiresAt = null): array
    {
        if (!in_array($source, ['admin_credit', 'topup', 'refund', 'cashback', 'reward', 'promotion', 'gift', 'voucher', 'migration'], true)) {
            throw new WalletException('invalid_source', 'Unknown credit source.');
        }
        return $this->kernel->execute($key, 'credit', [$accountId, $money->jsonSerialize(), $source, $availableAt, $expiresAt], [$accountId], $actorId, $reason, function (CommandContext $context) use ($accountId, $money, $source, $availableAt, $expiresAt): array {
            return $this->issue($context, $accountId, $money, $source, $availableAt, $expiresAt);
        });
    }

    /** For composed commands already running in the kernel's transaction. */
    public function issue(CommandContext $context, string $accountId, Money $money, string $source, ?int $availableAt = null, ?int $expiresAt = null): array
    {
        if (!in_array($source, ['admin_credit', 'topup', 'refund', 'cashback', 'reward', 'promotion', 'gift', 'voucher', 'migration'], true)) {
            throw new WalletException('invalid_source', 'Unknown credit source.');
        }
        $this->validateMoney($context, $accountId, $money);
        WalletState::from($context->accounts[$accountId]['state'])->assertAllows($source === 'refund' ? 'refund' : ($source === 'admin_credit' ? 'admin_credit' : 'credit'));
        $this->limits->check($context, $accountId, $money, true);
        $maturity = $availableAt ?? $context->now;
        if ($maturity < 0 || ($expiresAt !== null && $expiresAt <= max($context->now, $maturity))) {
            throw new WalletException('invalid_expiry', 'Expiry must be after availability and issuance.');
        }
        $bucket = $maturity > $context->now ? 'pending' : 'available';
        $lotId = $this->lots->create($context, $accountId, $money->minor, $source, $maturity, $expiresAt);
        $context->post(new Journal($money->currency, [new JournalLine(null, 'issuance', 'debit', $money), new JournalLine($accountId, $bucket, 'credit', $money)]));
        $result = ['reference' => $context->reference, 'entry_id' => $context->entryId, 'lot_id' => $lotId, 'account_id' => $accountId, 'amount' => $money->decimal(), 'currency' => $money->currency->code];
        $context->audit($accountId, ['amount_minor' => (string) $money->minor, 'source' => $source]);
        $context->event('wallet.credited', $result);
        return $result;
    }

    public function debit(string $accountId, Money $money, string $key, int $actorId, string $reason): array
    {
        return $this->kernel->execute($key, 'debit', [$accountId, $money->jsonSerialize()], [$accountId], $actorId, $reason, function (CommandContext $context) use ($accountId, $money): array {
            $this->validateMoney($context, $accountId, $money);
            WalletState::from($context->accounts[$accountId]['state'])->assertAllows('debit');
            $this->limits->check($context, $accountId, $money, false);
            $this->lots->consume($context, $accountId, $money->minor);
            $context->post(new Journal($money->currency, [new JournalLine($accountId, 'available', 'debit', $money), new JournalLine(null, 'spending', 'credit', $money)]));
            $result = ['reference' => $context->reference, 'entry_id' => $context->entryId, 'account_id' => $accountId, 'amount' => $money->decimal(), 'currency' => $money->currency->code];
            $context->audit($accountId, ['amount_minor' => (string) $money->minor]);
            $context->event('wallet.debited', $result);
            return $result;
        });
    }

    public function setState(string $accountId, WalletState $state, string $key, int $actorId, string $reason): array
    {
        return $this->kernel->execute($key, 'state', [$accountId, $state->value], [$accountId], $actorId, $reason, function (CommandContext $context) use ($accountId, $state): array {
            $before = $context->accounts[$accountId]['state'];
            if ($before === 'closed' && $state !== WalletState::Closed) {
                throw new WalletException('invalid_transition', 'Closed accounts cannot reopen.');
            }
            $context->db->execute('UPDATE ' . $context->db->table('accounts') . ' SET state=?,version=version+1 WHERE id=?', [$state->value, $accountId]);
            $context->audit($accountId, ['before' => $before, 'after' => $state->value]);
            $context->event('wallet.state_changed', ['account_id' => $accountId, 'state' => $state->value]);
            return ['account_id' => $accountId, 'state' => $state->value, 'reference' => $context->reference];
        });
    }

    public function validateMoney(CommandContext $context, string $accountId, Money $money): void
    {
        $money->positive();
        $account = $context->accounts[$accountId];
        if ($account['currency'] !== $money->currency->code || (int) $account['exponent'] !== $money->currency->exponent) {
            throw new WalletException('currency_mismatch', 'Wallet and command currency must match.');
        }
    }
}
