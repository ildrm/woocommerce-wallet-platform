<?php
declare(strict_types=1);

use WalletPlatform\Application\Port\PaymentProvider;
use WalletPlatform\Application\SplitPaymentService;
use WalletPlatform\Domain\Payment\PaymentIntent;
use WalletPlatform\Domain\Payment\ProviderOrder;
use WalletPlatform\Domain\Payment\ProviderRefund;
use WalletPlatform\Domain\Payment\SavedPayment;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

/** Protocol fixture only; never bundled with the plugin. */
final class SagaProviderFixture implements PaymentProvider
{
    public function supports(Money $amount): bool { return $amount->minor > 0; }
    public int $creates = 0;
    public int $captures = 0;
    public int $refundCalls = 0;
    public bool $loseCreate = false;
    public bool $loseCapture = false;
    public bool $loseRefund = false;
    public string $captureStatus = 'COMPLETED';
    public string $refundStatus = 'COMPLETED';
    public ?ProviderOrder $order = null;
    public array $refunds = [];
    public string $orderId = '5O190127TN364715T';
    public string $captureId = '3C679366HH908993F';

    public function __construct(bool $unique = false)
    {
        if ($unique) {
            $this->orderId = 'ORDER' . strtoupper(bin2hex(random_bytes(8)));
            $this->captureId = 'CAPTURE' . strtoupper(bin2hex(random_bytes(8)));
        }
    }

    public function create(PaymentIntent $intent, string $returnUrl, string $cancelUrl): ProviderOrder
    {
        ++$this->creates;
        $this->order = new ProviderOrder($this->orderId, 'CREATED', 'https://www.sandbox.paypal.com/checkoutnow?token=' . $this->orderId, null, null);
        if ($this->loseCreate) { throw new WalletException('provider_outcome_unknown', 'Lost creation response'); }
        return $this->order;
    }
    public function approve(): void { $this->order = new ProviderOrder($this->order->id, 'APPROVED', null, null, null); }
    public function inspect(string $orderId, PaymentIntent $intent): ProviderOrder { return $this->order; }
    public function capture(string $orderId, PaymentIntent $intent): ProviderOrder
    {
        ++$this->captures;
        $this->order = new ProviderOrder($orderId, 'COMPLETED', null, $this->captureId, $this->captureStatus);
        if ($this->loseCapture) { throw new WalletException('provider_outcome_unknown', 'Lost capture response'); }
        return $this->order;
    }
    public function refund(string $captureId, Money $amount, string $refundReference): ProviderRefund
    {
        ++$this->refundCalls;
        $refund = new ProviderRefund('REFUND' . strtoupper(bin2hex(random_bytes(8))), $this->refundStatus);
        $this->refunds[$refund->id] = ['resource' => $refund, 'amount' => $amount->minor, 'reference' => $refundReference];
        if ($this->loseRefund) { throw new WalletException('provider_outcome_unknown', 'Lost refund response'); }
        return $refund;
    }
    public function inspectRefund(string $refundId, string $captureId, Money $amount, string $refundReference): ProviderRefund
    {
        same($amount->minor, $this->refunds[$refundId]['amount']);
        same($refundReference, $this->refunds[$refundId]['reference']);
        return new ProviderRefund($refundId, $this->refundStatus);
    }
    public function chargeSaved(PaymentIntent $intent, SavedPayment $payment): ProviderOrder { throw new LogicException('Unexpected saved charge'); }
    public function verifyWebhook(string $rawBody, array $headers): array
    {
        if (($headers['verified'] ?? '') !== 'yes') { throw new WalletException('webhook_signature_invalid', 'Test signature rejected'); }
        return json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    }
}

function splitFixture(int $gross = 2500, int $wallet = 1000): array
{
    $e = environment();
    $currency = Currency::of('USD');
    $account = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($account, new Money(5000, $currency), 'credit', 1, 'Opening credit');
    $provider = new SagaProviderFixture();
    $saga = new SplitPaymentService($e['wallet'], $e['holds'], $e['refunds'], $provider);
    $payment = $saga->prepare(42, $account, new Money($gross, $currency), new Money($wallet, $currency), 'prepare', 1);
    return $e + compact('currency', 'account', 'provider', 'saga', 'payment');
}

function startSplit(array $e): void
{
    $e['saga']->start($e['payment']['id'], 1, 'https://store.example/return', 'https://store.example/cancel');
    $e['provider']->approve();
}

return [
    'reconciliation detects balanced external journal corruption and freezes without rewriting it' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['saga']->settle($e['payment']['id'], 1);
        $entry = $e['db']->row('SELECT id FROM ' . $e['db']->table('entries') . ' WHERE reference=?', ['cmd:' . hash('sha256', 'split:external:capture:' . $e['payment']['id'])]);
        $e['db']->execute('UPDATE ' . $e['db']->table('lines') . ' SET amount=amount+1 WHERE entry_id=?', [$entry['id']]);
        $result = $e['reconcile']->account($e['account'], true);
        same(false, $result['healthy']);
        same(true, $result['frozen']);
        same(true, in_array('external_capture_journal', array_column($result['issues'], 'type'), true));
        same('40.00', $e['query']->balance($e['account'])['available']);
        same(1501, (int) $e['db']->row('SELECT amount FROM ' . $e['db']->table('lines') . ' WHERE entry_id=? LIMIT 1', [$entry['id']])['amount']);
    },
    'reconciliation detects missing external refund journal and changed payment owner' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['saga']->settle($e['payment']['id'], 1);
        $refund = $e['saga']->refund($e['payment']['id'], new Money(2500, $e['currency']), 'all');
        same(true, $e['reconcile']->account($e['account'])['healthy']);
        $e['db']->execute('DELETE FROM ' . $e['db']->table('entries') . ' WHERE reference=?', ['cmd:' . hash('sha256', 'split:external:refund:' . $refund['id'])]);
        $e['db']->execute('UPDATE ' . $e['db']->table('payments') . ' SET owner_id=2 WHERE id=?', [$e['payment']['id']]);
        $result = $e['reconcile']->account($e['account']);
        same(false, $result['healthy']);
        same(false, $result['frozen']);
        same(true, in_array('external_refund_journal', array_column($result['issues'], 'type'), true));
        same(true, in_array('payment_allocation', array_column($result['issues'], 'type'), true));
        same('active', $e['query']->balance($e['account'])['state']);
    },
    'exact large-ratio allocation conserves cumulative tender' => static function (): void {
        $currency = Currency::of('USD');
        same(Money::MAX_MINOR - 1, (new Money(Money::MAX_MINOR, $currency))->prorate(Money::MAX_MINOR - 1, Money::MAX_MINOR)->minor);
        same(0, (new Money(1, $currency))->prorate(1, 3)->minor);
        for ($i = 0; $i < 100; ++$i) {
            $amount = random_int(0, 100000);
            $total = random_int(1, 100000);
            $weight = random_int(0, $total);
            same(intdiv($amount * $weight, $total), (new Money($amount, $currency))->prorate($weight, $total)->minor);
        }
    },
    'split payment requires owner immutable gross and both tenders' => static function (): void {
        $e = splitFixture();
        $id = $e['payment']['id'];
        same($id, $e['saga']->prepare(42, $e['account'], new Money(2500, $e['currency']), new Money(1000, $e['currency']), 'another-prepare-key', 1)['id']);
        rejects('allocation_conflict', fn () => $e['saga']->prepare(42, $e['account'], new Money(2600, $e['currency']), new Money(1000, $e['currency']), 'changed', 1));
        rejects('payment_owner_mismatch', fn () => $e['saga']->start($id, 2, 'https://store.example/return', 'https://store.example/cancel'));
        rejects('invalid_amount', fn () => $e['saga']->prepare(43, $e['account'], new Money(2500, $e['currency']), new Money(2500, $e['currency']), 'zero-external', 1));
        same(0, $e['provider']->creates);
    },
    'split approval reserves then captures once with unchanged merchandise gross' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        same('40.00', $e['query']->balance($e['account'])['available']);
        same('10.00', $e['query']->balance($e['account'])['reserved']);
        same('completed', $e['saga']->settle($e['payment']['id'], 1)['state']);
        same('completed', $e['saga']->settle($e['payment']['id'], 1)['state']);
        same(1, $e['provider']->creates);
        same(1, $e['provider']->captures);
        same('0.00', $e['query']->balance($e['account'])['reserved']);
        same(2500, (int) $e['saga']->record($e['payment']['id'])['gross']);
        same(1500, (int) $e['db']->row('SELECT SUM(CASE WHEN side=\'debit\' THEN amount ELSE -amount END) AS amount FROM ' . $e['db']->table('lines') . " WHERE bucket='paypal_clearing'")['amount']);
        same(true, $e['reconcile']->account($e['account'])['healthy']);
    },
    'lost split capture response is recovered with inspection without another capture' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['provider']->loseCapture = true;
        rejects('provider_outcome_unknown', fn () => $e['saga']->settle($e['payment']['id'], 1));
        same('completed', $e['saga']->recover($e['payment']['id'])['state']);
        same(1, $e['provider']->captures);
        same('40.00', $e['query']->balance($e['account'])['available']);
        same(true, $e['reconcile']->account($e['account'])['healthy']);
    },
    'pending external capture never spends wallet and is later inspected' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['provider']->captureStatus = 'PENDING';
        same('capture_pending', $e['saga']->settle($e['payment']['id'], 1)['state']);
        same('10.00', $e['query']->balance($e['account'])['reserved']);
        $e['provider']->order = new ProviderOrder($e['provider']->order->id, 'COMPLETED', null, '3C679366HH908993F', 'COMPLETED');
        same('completed', $e['saga']->recover($e['payment']['id'])['state']);
        same(1, $e['provider']->captures);
    },
    'lost external order creation fails closed rather than replaying a mutation' => static function (): void {
        $e = splitFixture();
        $e['provider']->loseCreate = true;
        for ($i = 0; $i < 2; ++$i) {
            rejects('provider_outcome_unknown', fn () => $e['saga']->start($e['payment']['id'], 1, 'https://store.example/return', 'https://store.example/cancel'));
        }
        same(1, $e['provider']->creates);
        same('cancelled', $e['saga']->cancel($e['payment']['id'], 1)['state']);
        same('50.00', $e['query']->balance($e['account'])['available']);
    },
    'expired split hold compensates settled external funds rather than fulfilling an order' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['provider']->captureStatus = 'PENDING';
        $e['saga']->settle($e['payment']['id'], 1);
        $e['clock']->time += 1801;
        $e['provider']->order = new ProviderOrder($e['provider']->order->id, 'COMPLETED', null, '3C679366HH908993F', 'COMPLETED');
        same('compensated', $e['saga']->recover($e['payment']['id'])['state']);
        same('50.00', $e['query']->balance($e['account'])['available']);
        same(1500, array_values($e['provider']->refunds)[0]['amount']);
        same(1, $e['provider']->refundCalls);
        same('compensated', $e['saga']->recover($e['payment']['id'])['state']);
        same(true, $e['reconcile']->account($e['account'])['healthy']);
    },
    'frozen split wallet compensates external settlement and preserves restriction' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['wallet']->setState($e['account'], \WalletPlatform\Domain\Wallet\WalletState::Frozen, 'freeze', 1, 'Risk review');
        same('compensated', $e['saga']->settle($e['payment']['id'], 1)['state']);
        same('50.00', $e['query']->balance($e['account'])['available']);
        same('frozen', $e['query']->balance($e['account'])['state']);
    },
    'mixed refunds preserve proportional cents ceiling and replay' => static function (): void {
        $e = splitFixture(3, 1);
        startSplit($e);
        $e['saga']->settle($e['payment']['id'], 1);
        foreach (['one', 'two', 'three'] as $reference) {
            $result = $e['saga']->refund($e['payment']['id'], new Money(1, $e['currency']), $reference);
            same('completed', $result['state']);
            same($result, $e['saga']->refund($e['payment']['id'], new Money(1, $e['currency']), $reference));
        }
        same(2, $e['provider']->refundCalls);
        same('50.00', $e['query']->balance($e['account'])['available']);
        rejects('refund_limit', fn () => $e['saga']->refund($e['payment']['id'], new Money(1, $e['currency']), 'four'));
        same(0, (int) $e['db']->row('SELECT SUM(CASE WHEN side=\'debit\' THEN amount ELSE -amount END) AS amount FROM ' . $e['db']->table('lines') . " WHERE bucket='paypal_clearing'")['amount']);
        rejects('idempotency_conflict', fn () => $e['saga']->refund($e['payment']['id'], new Money(2, $e['currency']), 'one'));
        same(true, $e['reconcile']->account($e['account'])['healthy']);
    },
    'pending mixed refunds reserve ceiling and restore wallet only after external proof' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['saga']->settle($e['payment']['id'], 1);
        $e['provider']->refundStatus = 'PENDING';
        $refund = $e['saga']->refund($e['payment']['id'], new Money(2500, $e['currency']), 'all');
        same('pending', $refund['state']);
        same('40.00', $e['query']->balance($e['account'])['available']);
        rejects('refund_limit', fn () => $e['saga']->refund($e['payment']['id'], new Money(1, $e['currency']), 'over-pending'));
        $e['provider']->refundStatus = 'COMPLETED';
        same('completed', $e['saga']->recoverRefund($refund['id'])['state']);
        same('50.00', $e['query']->balance($e['account'])['available']);
        same(1, $e['provider']->refundCalls);
    },
    'lost mixed refund response is never blindly refunded a second time' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['saga']->settle($e['payment']['id'], 1);
        $e['provider']->loseRefund = true;
        for ($i = 0; $i < 2; ++$i) {
            rejects('provider_outcome_unknown', fn () => $e['saga']->refund($e['payment']['id'], new Money(2500, $e['currency']), 'lost-refund'));
        }
        same(1, $e['provider']->refundCalls);
        same('40.00', $e['query']->balance($e['account'])['available']);
    },
    'split cancellation restores both tenders once including pending capture recovery' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['provider']->captureStatus = 'PENDING';
        $e['saga']->settle($e['payment']['id'], 1);
        same('cancellation_pending', $e['saga']->cancel($e['payment']['id'], 1)['state']);
        same('50.00', $e['query']->balance($e['account'])['available']);
        $e['provider']->order = new ProviderOrder($e['provider']->order->id, 'COMPLETED', null, '3C679366HH908993F', 'COMPLETED');
        same('compensated', $e['saga']->recover($e['payment']['id'])['state']);
        same(1, $e['provider']->refundCalls);
        same('compensated', $e['saga']->cancel($e['payment']['id'], 1)['state']);
        same(true, $e['reconcile']->account($e['account'])['healthy']);
    },
    'verified webhook binds a lost refund response and recovers without another refund' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['saga']->settle($e['payment']['id'], 1);
        $e['provider']->loseRefund = true;
        rejects('provider_outcome_unknown', fn () => $e['saga']->refund($e['payment']['id'], new Money(2500, $e['currency']), 'lost-refund'));
        $refund = $e['db']->row('SELECT * FROM ' . $e['db']->table('payment_refunds') . ' WHERE reference=?', ['lost-refund']);
        $raw = json_encode(['id' => 'WH-REFUND-001', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => array_key_first($e['provider']->refunds), 'invoice_id' => $refund['id']]], JSON_THROW_ON_ERROR);
        same('bound', $e['saga']->receiveWebhook($raw, ['verified' => 'yes'])['state']);
        same('completed', $e['saga']->recoverRefund($refund['id'])['state']);
        same('50.00', $e['query']->balance($e['account'])['available']);
        same(1, $e['provider']->refundCalls);
        $events = (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('outbox'))['n'];
        $e['saga']->receiveWebhook($raw, ['verified' => 'yes']);
        same($events, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('outbox'))['n']);
        rejects('webhook_replay_conflict', fn () => $e['saga']->receiveWebhook(str_replace('WH-REFUND-001', 'WH-REFUND-001', $raw) . ' ', ['verified' => 'yes']));
        same(true, $e['reconcile']->account($e['account'])['healthy']);
    },
    'forged webhooks never persist a receipt or change wallet value' => static function (): void {
        $e = splitFixture();
        $raw = json_encode(['id' => 'WH-FORGED', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['invoice_id' => $e['payment']['id']]], JSON_THROW_ON_ERROR);
        rejects('webhook_signature_invalid', fn () => $e['saga']->receiveWebhook($raw, []));
        same(0, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('provider_events'))['n']);
        same('50.00', $e['query']->balance($e['account'])['available']);
    },
    'verified capture webhook maps uncertain creation before any financial completion' => static function (): void {
        $e = splitFixture();
        $e['provider']->loseCreate = true;
        rejects('provider_outcome_unknown', fn () => $e['saga']->start($e['payment']['id'], 1, 'https://store.example/return', 'https://store.example/cancel'));
        $e['provider']->order = new ProviderOrder($e['provider']->order->id, 'COMPLETED', null, '3C679366HH908993F', 'COMPLETED');
        $raw = json_encode(['id' => 'WH-CAPTURE-001', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['invoice_id' => $e['payment']['id'], 'supplementary_data' => ['related_ids' => ['order_id' => $e['provider']->order->id]]]], JSON_THROW_ON_ERROR);
        same('bound', $e['saga']->receiveWebhook($raw, ['verified' => 'yes'])['state']);
        same('completed', $e['saga']->recover($e['payment']['id'])['state']);
        same(0, $e['provider']->captures);
        same('40.00', $e['query']->balance($e['account'])['available']);
    },
    'active split processing lease rejects overlapping operations and resumes after a crash' => static function (): void {
        $e = splitFixture();
        startSplit($e);
        $e['db']->execute('UPDATE ' . $e['db']->table('payments') . ' SET lease_until=?,lease_token=? WHERE id=?', [$e['clock']->now() + 180, str_repeat('b', 32), $e['payment']['id']]);
        rejects('concurrency_conflict', fn () => $e['saga']->settle($e['payment']['id'], 1));
        same(0, $e['provider']->captures);
        $e['clock']->time += 181;
        same('completed', $e['saga']->settle($e['payment']['id'], 1)['state']);
        same(1, $e['provider']->captures);
    },
];
