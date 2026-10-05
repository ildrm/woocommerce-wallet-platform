<?php

declare(strict_types=1);

namespace WalletPlatform\Application;

use WalletPlatform\Application\Port\PaymentProvider;
use WalletPlatform\Domain\Payment\PaymentIntent;
use WalletPlatform\Domain\Payment\ProviderOrder;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Domain\Wallet\WalletState;
use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Ledger\JournalLine;

/** Durable split-tender saga. Trusted adapters authorize callers and verify the current merchandise order. */
final class SplitPaymentService
{
    private ?string $lease = null;

    public function __construct(private readonly WalletService $wallets, private readonly HoldService $holds, private readonly RefundService $refunds, private readonly PaymentProvider $provider)
    {
    }

    public function prepare(int $orderId, string $accountId, Money $gross, Money $wallet, string $key, int $owner): array
    {
        $gross->positive();
        $wallet->positive();
        $external = $gross->subtract($wallet);
        $external->positive();
        if (!$this->provider->supports($external)) {
            throw new WalletException('provider_amount_unsupported', 'External currency or precision is not supported.');
        }
        if ($orderId <= 0 || $owner <= 0) {
            throw new WalletException('invalid_payment_intent', 'Split payment requires a merchandise order and owner.');
        }
        $result = $this->wallets->kernel->execute($key, 'split_prepare', [$orderId, $accountId, $gross->jsonSerialize(), $wallet->jsonSerialize()], [$accountId], $owner, 'Prepare wallet and external tender', function (CommandContext $context) use ($orderId, $accountId, $gross, $wallet, $external, $owner): array {
            $this->wallets->validateMoney($context, $accountId, $gross);
            WalletState::from($context->accounts[$accountId]['state'])->assertAllows('reserve');
            if ((int) $context->accounts[$accountId]['user_id'] !== $owner) {
                throw new WalletException('payment_owner_mismatch', 'Payment belongs to another wallet owner.');
            }
            $db = $context->db;
            $previous = $db->row('SELECT * FROM ' . $db->table('allocations') . ' WHERE order_id=? ORDER BY attempt DESC LIMIT 1' . $db->lockSuffix(), [$orderId]);
            if ($previous !== null) {
                $payment = $db->row('SELECT id FROM ' . $db->table('payments') . ' WHERE allocation_id=?', [$previous['id']]);
                if ($payment === null || $previous['account_id'] !== $accountId || (int) $previous['gross'] !== $gross->minor || (int) $previous['wallet_amount'] !== $wallet->minor || $previous['currency'] !== $gross->currency->code) {
                    throw new WalletException('allocation_conflict', 'Order has a different immutable payment allocation.');
                }
                return ['payment_id' => $payment['id']];
            }
            $id = CommandContext::id();
            $db->insert('allocations', ['id' => $id, 'order_id' => $orderId, 'attempt' => 1, 'account_id' => $accountId, 'gross' => $gross->minor, 'wallet_amount' => $wallet->minor, 'external_amount' => $external->minor, 'currency' => $gross->currency->code, 'expires_at' => $context->now + 1800, 'created_at' => $context->now]);
            $db->insert('payments', ['id' => $id, 'allocation_id' => $id, 'owner_id' => $owner, 'provider' => 'paypal', 'state' => 'prepared', 'created_at' => $context->now, 'updated_at' => $context->now]);
            $context->audit($accountId, ['payment_id' => $id, 'order_id' => $orderId, 'wallet_minor' => (string) $wallet->minor, 'external_minor' => (string) $external->minor]);
            return ['payment_id' => $id];
        });
        return $this->record($result['payment_id']);
    }

    public function start(string $id, int $owner, string $returnUrl, string $cancelUrl): array
    {
        $this->assertOwner($this->record($id), $owner);
        return $this->locked($id, function () use ($id, $returnUrl, $cancelUrl): array {
            $payment = $this->record($id);
            if ((int) $payment['cancel_requested'] === 1 || in_array($payment['state'], ['completed', 'cancelled', 'compensated'], true)) {
                return $payment;
            }
            if ($payment['provider_order'] !== null) {
                return $payment;
            }
            if ($payment['create_started'] !== null) {
                // Never replay an unconfirmed mutation outside a verified provider retention contract.
                throw new WalletException('provider_outcome_unknown', 'Payment creation requires inspection of its original reference.');
            }
            if ($payment['hold_id'] === null) {
                $held = $this->holds->reserve($payment['account_id'], $this->money($payment, 'wallet_amount'), 'split:' . $id, (int) $payment['expires_at'], 'split:reserve:' . $id, (int) $payment['owner_id']);
                $this->db()->execute('UPDATE ' . $this->db()->table('allocations') . ' SET hold_id=? WHERE id=? AND hold_id IS NULL', [$held['hold_id'], $payment['allocation_id']]);
                $payment = $this->record($id);
            }
            $hold = $this->holds->hold($payment['hold_id']);
            if ($hold['state'] !== 'held' || (int) $hold['expires_at'] <= $this->now()) {
                throw new WalletException('hold_unavailable', 'Reservation expired before external payment creation.');
            }
            $this->transition($id, 'creating', ['create_started' => $this->now()]);
            try {
                $created = $this->provider->create($this->intent($payment), $returnUrl, $cancelUrl);
                $this->saveProvider($id, $created);
                return $this->record($id);
            } catch (\Throwable $error) {
                $this->transition($id, 'review', ['error_code' => $this->errorCode($error)]);
                throw $error;
            }
        });
    }

    /** Capture only after verified approval; pending/unknown resources are inspected without another POST. */
    public function settle(string $id, int $owner): array
    {
        $this->assertOwner($this->record($id), $owner);
        return $this->recover($id, true);
    }

    /** Trusted scheduler/webhook path. Callers must inspect current WooCommerce state before permitting capture. */
    public function recover(string $id, bool $allowCapture = false): array
    {
        return $this->locked($id, function () use ($id, $allowCapture): array {
            $payment = $this->record($id);
            if (in_array($payment['state'], ['completed', 'cancelled', 'compensated'], true)) {
                return $payment;
            }
            if ((int) $payment['cancel_requested'] && (int) $payment['external_settled']) {
                return $this->compensate($payment);
            }
            if ($payment['provider_order'] === null) {
                return $payment;
            }
            try {
                $resource = $this->provider->inspect($payment['provider_order'], $this->intent($payment));
                if (!$resource->settled() && $resource->captureId === null && $resource->status === 'APPROVED' && $allowCapture && !(int) $payment['cancel_requested']) {
                    if ($payment['capture_started'] !== null) {
                        throw new WalletException('provider_outcome_unknown', 'Capture outcome requires review of its original reference.');
                    }
                    $hold = $this->holds->hold((string) $payment['hold_id']);
                    if ($hold['state'] !== 'held' || (int) $hold['expires_at'] <= $this->now()) {
                        $this->release($payment);
                        $this->transition($id, 'cancelled', ['cancel_requested' => 1]);
                        return $this->record($id);
                    }
                    $this->transition($id, 'capturing', ['capture_started' => $this->now()]);
                    $resource = $this->provider->capture($payment['provider_order'], $this->intent($payment));
                }
                $this->saveProvider($id, $resource);
                $payment = $this->record($id);
                if ($resource->settled()) {
                    if ((int) $payment['cancel_requested']) {
                        return $this->compensate($payment);
                    }
                    try {
                        $this->assertLease($id);
                        $this->holds->capture((string) $payment['hold_id'], 'split:capture:' . $id, (int) $payment['owner_id']);
                    } catch (WalletException $error) {
                        if (!in_array($error->errorCode, ['hold_unavailable', 'wallet_unavailable'], true)) {
                            throw $error;
                        }
                        $this->transition($id, 'compensating', ['cancel_requested' => 1, 'error_code' => $error->errorCode]);
                        return $this->compensate($this->record($id));
                    }
                    $this->transition($id, 'completed');
                } elseif (in_array($resource->captureStatus, ['FAILED', 'DECLINED'], true) || $resource->status === 'VOIDED') {
                    $this->release($payment);
                    $this->transition($id, 'cancelled', ['cancel_requested' => 1]);
                } elseif ((int) $payment['cancel_requested']) {
                    $this->release($payment);
                    $this->transition($id, $resource->captureId === null && $payment['capture_started'] === null ? 'cancelled' : 'cancellation_pending');
                }
                return $this->record($id);
            } catch (\Throwable $error) {
                $this->transition($id, 'review', ['error_code' => $this->errorCode($error)]);
                throw $error;
            }
        });
    }

    public function cancel(string $id, int $owner): array
    {
        $this->assertOwner($this->record($id), $owner);
        return $this->locked($id, function () use ($id): array {
            $payment = $this->record($id);
            if (in_array($payment['state'], ['cancelled', 'compensated'], true)) {
                return $payment;
            }
            $this->transition($id, 'cancellation_pending', ['cancel_requested' => 1]);
            $payment = $this->record($id);
            $this->release($payment);
            if ($payment['capture_id'] !== null) {
                $resource = $this->provider->inspect($payment['provider_order'], $this->intent($payment));
                if ($resource->settled()) {
                    return $this->compensate($payment);
                }
            }
            if ($payment['capture_started'] === null) {
                $this->transition($id, 'cancelled');
            }
            return $this->record($id);
        });
    }

    /** Staff adapter supplies a persisted refund reference; pending intents consume the refund ceiling too. */
    public function refund(string $id, Money $amount, string $reference): array
    {
        return $this->locked($id, function () use ($id, $amount, $reference): array {
            $payment = $this->record($id);
            if ($payment['state'] !== 'completed' || (int) $payment['cancel_requested']) {
                throw new WalletException('payment_unavailable', 'Payment is not eligible for a merchant refund.');
            }
            $refund = $this->prepareRefund($payment, $amount, $reference, true);
            return $this->processRefund($payment, $refund);
        });
    }

    /** Persist adapter recovery data before contacting the provider. No financial effect yet. */
    public function prepareRefundIntent(string $id, Money $amount, string $reference, array $adapterContext): array
    {
        $encoded = json_encode($adapterContext, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new WalletException('invalid_refund_context', 'Refund recovery context exceeds its bound.');
        }
        return $this->locked($id, function () use ($id, $amount, $reference, $encoded): array {
            $payment = $this->record($id);
            if ($payment['state'] !== 'completed' || (int) $payment['cancel_requested']) {
                throw new WalletException('payment_unavailable', 'Payment is not eligible for a merchant refund.');
            }
            $refund = $this->prepareRefund($payment, $amount, $reference, true);
            if ($refund['adapter_context'] !== null && $refund['adapter_context'] !== $encoded) {
                throw new WalletException('idempotency_conflict', 'Refund recovery context differs from its original snapshot.');
            }
            $this->db()->execute('UPDATE ' . $this->db()->table('payment_refunds') . ' SET adapter_context=? WHERE id=? AND adapter_context IS NULL', [$encoded, $refund['id']]);
            return $this->refundRecord($refund['id']);
        });
    }

    public function recoverRefund(string $refundId): array
    {
        $record = $this->refundRecord($refundId);
        return $this->locked($record['payment_id'], fn (): array => $this->processRefund($this->record($record['payment_id']), $this->refundRecord($refundId)));
    }

    /** Verify first, store a receipt, then use provider GETs to bind identifiers. The receipt survives process death. */
    public function receiveWebhook(string $rawBody, array $headers): array
    {
        $event = $this->provider->verifyWebhook($rawBody, $headers);
        $eventId = (string) ($event['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId)) {
            throw new WalletException('invalid_webhook', 'Webhook event requires a bounded provider reference.');
        }
        $db = $this->db();
        $hash = hash('sha256', $rawBody);
        $receipt = $db->transaction(function () use ($db, $eventId, $hash): array {
            $db->insert('provider_events', ['id' => $eventId, 'provider' => 'paypal', 'body_hash' => $hash, 'state' => 'received', 'created_at' => $this->now()], true);
            $record = $db->row('SELECT * FROM ' . $db->table('provider_events') . ' WHERE id=?' . $db->lockSuffix(), [$eventId]);
            if ($record === null || !hash_equals($record['body_hash'], $hash)) {
                throw new WalletException('webhook_replay_conflict', 'Webhook reference was already used for another payload.');
            }
            return $record;
        });
        if ($receipt['state'] === 'bound' || $receipt['state'] === 'ignored') {
            return $receipt;
        }
        $resource = $event['resource'] ?? [];
        $type = (string) ($event['event_type'] ?? '');
        $refund = null;
        $payment = null;
        if (in_array($type, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.REFUND.PENDING', 'PAYMENT.REFUND.FAILED'], true)) {
            $candidate = (string) ($resource['invoice_id'] ?? '');
            $refund = $db->row('SELECT * FROM ' . $db->table('payment_refunds') . ' WHERE id=?', [$candidate]);
            if ($refund !== null) {
                $payment = $this->record($refund['payment_id']);
            }
        } elseif (in_array($type, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED'], true)) {
            $orderId = $type === 'CHECKOUT.ORDER.APPROVED' ? (string) ($resource['id'] ?? '') : (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
            $candidate = (string) ($resource['purchase_units'][0]['custom_id'] ?? $resource['custom_id'] ?? $resource['invoice_id'] ?? '');
            $mapping = $db->row('SELECT id FROM ' . $db->table('payments') . ' WHERE provider_order=?', [$orderId]);
            if ($mapping === null && preg_match('/^[a-f0-9]{32}$/D', $candidate)) {
                $mapping = $db->row('SELECT id FROM ' . $db->table('payments') . ' WHERE id=?', [$candidate]);
            }
            if ($mapping !== null && $orderId !== '') {
                $payment = $this->record($mapping['id']);
                if ($payment['create_started'] === null || $payment['hold_id'] === null) {
                    throw new WalletException('provider_response_mismatch', 'Provider event has no submitted local payment intent.');
                }
                $this->locked($payment['id'], function () use ($payment, $orderId): array {
                    $this->saveProvider($payment['id'], $this->provider->inspect($orderId, $this->intent($payment)));
                    return [];
                });
            }
        }
        if ($payment !== null && $refund !== null) {
            $this->locked($payment['id'], function () use ($payment, $refund, $resource): array {
                $providerRefund = (string) ($resource['id'] ?? '');
                if ($refund['started_at'] === null || $payment['capture_id'] === null || ($refund['provider_refund'] !== null && $refund['provider_refund'] !== $providerRefund)) {
                    throw new WalletException('provider_response_mismatch', 'Refund event has no matching submitted refund intent.');
                }
                $verified = $this->provider->inspectRefund($providerRefund, $payment['capture_id'], $this->money($payment, 'currency', (int) $refund['external_amount']), $refund['id']);
                $this->assertLease($payment['id']);
                $this->db()->execute('UPDATE ' . $this->db()->table('payment_refunds') . ' SET provider_refund=?,state=? WHERE id=?', [$verified->id, $verified->settled() ? 'external_settled' : 'pending', $refund['id']]);
                return [];
            });
        }
        $db->transaction(function () use ($db, $payment, $refund, $eventId): void {
            $current = $db->row('SELECT state FROM ' . $db->table('provider_events') . ' WHERE id=?' . $db->lockSuffix(), [$eventId]);
            if (($current['state'] ?? '') === 'bound' || ($current['state'] ?? '') === 'ignored') {
                return;
            }
            $db->execute('UPDATE ' . $db->table('provider_events') . ' SET state=?,payment_id=? WHERE id=?', [$payment === null ? 'ignored' : 'bound', $payment['id'] ?? null, $eventId]);
            if ($payment !== null) {
                $context = new CommandContext($db, $this->now(), 'paypal:event:' . $eventId, 'provider_webhook', 0, 'Verified PayPal event', []);
                $context->audit($payment['account_id'], ['payment_id' => $payment['id'], 'event_id' => $eventId]);
                $context->event('paypal.resource_verified', ['payment_id' => $payment['id'], 'refund_id' => $refund['id'] ?? null, 'event_id' => $eventId]);
            }
        });
        return $db->row('SELECT * FROM ' . $db->table('provider_events') . ' WHERE id=?', [$eventId]) ?? throw new WalletException('webhook_not_found', 'Webhook receipt is missing.');
    }

    private function compensate(array $payment): array
    {
        $this->release($payment);
        $captured = $payment['hold_id'] !== null && $this->holds->hold($payment['hold_id'])['state'] === 'captured';
        $prior = $this->db()->row('SELECT COALESCE(SUM(gross),0) AS amount FROM ' . $this->db()->table('payment_refunds') . ' WHERE payment_id=?', [$payment['id']]);
        $remaining = ($captured ? (int) $payment['gross'] : (int) $payment['external_amount']) - (int) ($prior['amount'] ?? 0);
        if ($remaining > 0) {
            $this->prepareRefund($payment, new Money($remaining, Currency::of($payment['currency'])), 'compensation:' . $payment['id'], $captured, true);
        }
        $records = $this->db()->rows('SELECT * FROM ' . $this->db()->table('payment_refunds') . " WHERE payment_id=? AND state<>'completed' ORDER BY created_at,id LIMIT 2", [$payment['id']]);
        foreach ($records as $record) {
            $this->processRefund($payment, $record);
        }
        $complete = $this->db()->row('SELECT id FROM ' . $this->db()->table('payment_refunds') . " WHERE payment_id=? AND state<>'completed' LIMIT 1", [$payment['id']]) === null;
        $this->transition($payment['id'], $complete ? 'compensated' : 'compensating');
        return $this->record($payment['id']);
    }

    private function prepareRefund(array $payment, Money $amount, string $reference, bool $restoreWallet, bool $compensation = false): array
    {
        $amount->positive();
        $this->money($payment, 'gross')->compare($amount);
        if ($reference === '' || strlen($reference) > 191 || !preg_match('/^[\x21-\x7e]+$/D', $reference)) {
            throw new WalletException('invalid_reference', 'Refund requires a stable bounded reference.');
        }
        $db = $this->db();
        return $db->transaction(function () use ($db, $payment, $amount, $reference, $restoreWallet, $compensation): array {
            $db->row('SELECT id FROM ' . $db->table('payments') . ' WHERE id=?' . $db->lockSuffix(), [$payment['id']]);
            $existing = $db->row('SELECT * FROM ' . $db->table('payment_refunds') . ' WHERE reference=?', [$reference]);
            if ($existing !== null) {
                if ($existing['payment_id'] !== $payment['id'] || (int) $existing['gross'] !== $amount->minor) {
                    throw new WalletException('idempotency_conflict', 'Refund reference was used for another amount or payment.');
                }
                return $existing;
            }
            $prior = $db->row('SELECT COALESCE(SUM(gross),0) AS gross,COALESCE(SUM(wallet_amount),0) AS wallet_amount,COALESCE(SUM(external_amount),0) AS external_amount FROM ' . $db->table('payment_refunds') . ' WHERE payment_id=?', [$payment['id']]);
            $ceiling = $restoreWallet ? (int) $payment['gross'] : (int) $payment['external_amount'];
            if ($amount->minor > $ceiling - (int) $prior['gross']) {
                throw new WalletException('refund_limit', 'Cumulative refund exceeds original tender.');
            }
            if (!$compensation && $db->row('SELECT id FROM ' . $db->table('payment_refunds') . " WHERE payment_id=? AND state<>'completed' LIMIT 1", [$payment['id']]) !== null) {
                throw new WalletException('refund_in_progress', 'Resolve the existing pending refund before creating another refund.');
            }
            $wallet = $restoreWallet ? (new Money((int) $prior['gross'] + $amount->minor, $amount->currency))->prorate((int) $payment['wallet_amount'], (int) $payment['gross'])->minor - (int) $prior['wallet_amount'] : 0;
            $external = $amount->minor - $wallet;
            if ($wallet < 0 || $external < 0 || $external > (int) $payment['external_amount'] - (int) $prior['external_amount']) {
                throw new WalletException('refund_limit', 'Refund allocation differs from original tender.');
            }
            $record = ['id' => CommandContext::id(), 'payment_id' => $payment['id'], 'reference' => $reference, 'gross' => $amount->minor, 'wallet_amount' => $wallet, 'external_amount' => $external, 'state' => 'prepared', 'created_at' => $this->now()];
            $db->insert('payment_refunds', $record);
            (new CommandContext($db, $this->now(), 'refund:' . $record['id'], 'split_refund_prepare', 0, 'Persist mixed-tender refund', []))->audit($payment['account_id'], ['payment_id' => $payment['id'], 'refund_id' => $record['id'], 'wallet_minor' => (string) $wallet, 'external_minor' => (string) $external]);
            return $this->refundRecord($record['id']);
        });
    }

    private function processRefund(array $payment, array $refund): array
    {
        if ($refund['state'] === 'completed') {
            return $refund;
        }
        $db = $this->db();
        $this->assertLease($payment['id']);
        if ((int) $refund['external_amount'] > 0) {
            $amount = $this->money($payment, 'currency', (int) $refund['external_amount']);
            if ($refund['provider_refund'] !== null) {
                $resource = $this->provider->inspectRefund($refund['provider_refund'], $payment['capture_id'], $amount, $refund['id']);
            } else {
                if ($refund['started_at'] !== null) {
                    throw new WalletException('provider_outcome_unknown', 'Refund outcome requires inspection; no second refund is sent.');
                }
                if ($payment['capture_id'] === null) {
                    throw new WalletException('payment_unavailable', 'External capture is not verified.');
                }
                $db->execute('UPDATE ' . $db->table('payment_refunds') . " SET started_at=?,state='requesting' WHERE id=? AND started_at IS NULL", [$this->now(), $refund['id']]);
                $resource = $this->provider->refund($payment['capture_id'], $amount, $refund['id']);
                $this->assertLease($payment['id']);
                $db->execute('UPDATE ' . $db->table('payment_refunds') . ' SET provider_refund=?,state=? WHERE id=?', [$resource->id, $resource->settled() ? 'external_settled' : 'pending', $refund['id']]);
            }
            if (!$resource->settled()) {
                if (in_array($resource->status, ['FAILED', 'CANCELLED'], true)) {
                    $db->execute('UPDATE ' . $db->table('payment_refunds') . " SET state='review' WHERE id=?", [$refund['id']]);
                    throw new WalletException('refund_review', 'External refund failed; its original reference requires review.');
                }
                return $this->refundRecord($refund['id']);
            }
            $this->wallets->kernel->execute('split:external:refund:' . $refund['id'], 'split_external_refund', [$refund['id'], $resource->id, $amount->jsonSerialize()], [$payment['account_id']], 0, 'Verified PayPal gross refund', static function (CommandContext $context) use ($payment, $refund, $resource, $amount): array {
                $context->post(new Journal($amount->currency, [new JournalLine(null, 'external_spending', 'debit', $amount), new JournalLine(null, 'paypal_clearing', 'credit', $amount)]));
                $context->audit($payment['account_id'], ['payment_id' => $payment['id'], 'refund_id' => $refund['id'], 'provider_refund' => $resource->id]);
                return ['refund_id' => $refund['id']];
            });
        }
        $this->assertLease($payment['id']);
        if ((int) $refund['wallet_amount'] > 0) {
            $this->refunds->refund($payment['hold_id'], $this->money($payment, 'currency', (int) $refund['wallet_amount']), 'split:refund:' . $refund['id'], 0, 'Mixed-tender refund ' . $refund['reference']);
        }
        $this->wallets->kernel->execute('split:refund:complete:' . $refund['id'], 'split_refund_complete', [$refund['id']], [$payment['account_id']], 0, 'Complete mixed-tender refund', function (CommandContext $context) use ($payment, $refund): array {
            $context->db->execute('UPDATE ' . $context->db->table('payment_refunds') . " SET state='completed' WHERE id=?", [$refund['id']]);
            $context->audit($payment['account_id'], ['payment_id' => $payment['id'], 'refund_id' => $refund['id']]);
            $context->event('split.refunded', ['payment_id' => $payment['id'], 'refund_id' => $refund['id'], 'reference' => $refund['reference']]);
            return ['refund_id' => $refund['id']];
        });
        return $this->refundRecord($refund['id']);
    }

    private function saveProvider(string $id, ProviderOrder $resource): void
    {
        $payment = $this->record($id);
        if (($payment['provider_order'] !== null && $payment['provider_order'] !== $resource->id) || ($payment['capture_id'] !== null && $payment['capture_id'] !== $resource->captureId)) {
            throw new WalletException('provider_response_mismatch', 'Provider resource differs from its durable mapping.');
        }
        if ($resource->settled()) {
            $this->assertLease($id);
            $amount = $this->money($payment, 'external_amount');
            $this->wallets->kernel->execute('split:external:capture:' . $id, 'split_external_capture', [$id, $resource->captureId, $amount->jsonSerialize()], [$payment['account_id']], 0, 'Verified PayPal gross capture', static function (CommandContext $context) use ($payment, $resource, $amount): array {
                $context->post(new Journal($amount->currency, [new JournalLine(null, 'paypal_clearing', 'debit', $amount), new JournalLine(null, 'external_spending', 'credit', $amount)]));
                $context->audit($payment['account_id'], ['payment_id' => $payment['id'], 'provider_order' => $resource->id, 'capture_id' => $resource->captureId]);
                return ['payment_id' => $payment['id'], 'capture_id' => $resource->captureId];
            });
        }
        $state = in_array($payment['state'], ['completed', 'compensated'], true) ? $payment['state'] : ($resource->settled() ? 'external_settled' : ($resource->captureId === null ? 'awaiting_approval' : 'capture_pending'));
        $this->transition($id, $state, ['provider_order' => $resource->id, 'capture_id' => $resource->captureId, 'approval_url' => $resource->approvalUrl, 'external_settled' => $resource->settled() || (int) $payment['external_settled'] ? 1 : 0, 'error_code' => null]);
    }

    private function release(array $payment): void
    {
        if ($payment['hold_id'] !== null && $this->holds->hold($payment['hold_id'])['state'] === 'held') {
            $this->assertLease($payment['id']);
            $this->holds->release($payment['hold_id'], 'split:release:' . $payment['id'], 0);
        }
    }

    public function record(string $id): array
    {
        $db = $this->db();
        return $db->row('SELECT p.*,a.order_id,a.account_id,a.hold_id,a.gross,a.wallet_amount,a.external_amount,a.currency,a.expires_at FROM ' . $db->table('payments') . ' p JOIN ' . $db->table('allocations') . ' a ON a.id=p.allocation_id WHERE p.id=?', [$id]) ?? throw new WalletException('payment_not_found', 'Payment does not exist.');
    }

    public function supports(Money $amount): bool
    {
        return $this->provider->supports($amount);
    }

    public function refundRecord(string $id): array
    {
        return $this->db()->row('SELECT * FROM ' . $this->db()->table('payment_refunds') . ' WHERE id=?', [$id]) ?? throw new WalletException('refund_not_found', 'Refund intent does not exist.');
    }

    private function transition(string $id, string $state, array $fields = []): void
    {
        $db = $this->db();
        $db->transaction(function () use ($db, $id, $state, $fields): void {
            $this->assertLease($id);
            $payment = $this->record($id);
            $values = ['state' => $state, 'updated_at' => $this->now()] + $fields;
            foreach (array_keys($values) as $field) {
                if (!in_array($field, ['state', 'updated_at', 'provider_order', 'capture_id', 'approval_url', 'external_settled', 'create_started', 'capture_started', 'cancel_requested', 'error_code'], true)) {
                    throw new \LogicException('Invalid payment transition field.');
                }
            }
            $db->execute('UPDATE ' . $db->table('payments') . ' SET ' . implode(',', array_map(static fn (string $column): string => $column . '=?', array_keys($values))) . ' WHERE id=? AND lease_token=?', [...array_values($values), $id, $this->lease]);
            $context = new CommandContext($db, $this->now(), 'payment:' . $id . ':' . CommandContext::id(), 'split_transition', 0, 'Payment status changed', []);
            $context->audit($payment['account_id'], ['payment_id' => $id, 'state' => $state, 'provider_order' => $fields['provider_order'] ?? $payment['provider_order'], 'capture_id' => $fields['capture_id'] ?? $payment['capture_id']]);
            if (in_array($state, ['completed', 'compensated'], true) && $payment['state'] !== $state) {
                $context->event('split.' . $state, ['payment_id' => $id, 'order_id' => (int) $payment['order_id']]);
            }
        });
    }

    private function locked(string $id, callable $work): array
    {
        if ($this->lease !== null) {
            throw new WalletException('concurrency_conflict', 'Nested payment operations are prohibited.');
        }
        $db = $this->db();
        $token = CommandContext::id();
        $db->transaction(function () use ($db, $id, $token): void {
            $row = $db->row('SELECT lease_until FROM ' . $db->table('payments') . ' WHERE id=?' . $db->lockSuffix(), [$id]);
            if ($row === null || (int) $row['lease_until'] > $this->now()) {
                throw new WalletException('concurrency_conflict', 'Payment is already processing.');
            }
            $db->execute('UPDATE ' . $db->table('payments') . ' SET lease_until=?,lease_token=? WHERE id=?', [$this->now() + 180, $token, $id]);
        });
        $this->lease = $token;
        try {
            return $work();
        } finally {
            $db->execute('UPDATE ' . $db->table('payments') . ' SET lease_until=0,lease_token=NULL WHERE id=? AND lease_token=?', [$id, $token]);
            $this->lease = null;
        }
    }

    private function assertLease(string $id): void
    {
        $row = $this->db()->row('SELECT lease_token,lease_until FROM ' . $this->db()->table('payments') . ' WHERE id=?', [$id]);
        if ($this->lease === null || ($row['lease_token'] ?? null) !== $this->lease || (int) ($row['lease_until'] ?? 0) <= $this->now()) {
            throw new WalletException('concurrency_conflict', 'Payment processing lease expired.');
        }
    }

    private function assertOwner(array $payment, int $owner): void
    {
        if ($owner <= 0 || (int) $payment['owner_id'] !== $owner) {
            throw new WalletException('payment_owner_mismatch', 'Payment belongs to another owner.');
        }
    }

    private function intent(array $payment): PaymentIntent
    {
        return new PaymentIntent($payment['id'], (int) $payment['owner_id'], $this->money($payment, 'external_amount'));
    }

    private function money(array $payment, string $field, ?int $minor = null): Money
    {
        return new Money($minor ?? (int) $payment[$field], Currency::of($payment['currency']));
    }

    private function db(): \WalletPlatform\Application\Port\Database
    {
        return $this->wallets->kernel->db;
    }

    private function now(): int
    {
        return $this->wallets->kernel->clock->now();
    }

    private function errorCode(\Throwable $error): string
    {
        return $error instanceof WalletException ? substr($error->errorCode, 0, 64) : 'payment_processing_error';
    }
}
