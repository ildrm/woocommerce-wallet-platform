<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WalletPlatform\Domain\Ledger\Journal;
use WalletPlatform\Domain\Ledger\JournalLine;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Wallet\WalletState;
use WalletPlatform\Infrastructure\Database\Schema;

$tests = [];
$tests['canonical money and exponents'] = static function (): void {
    foreach (['USD' => ['123.45', 12345], 'JPY' => ['123', 123], 'KWD' => ['1.234', 1234], 'CLF' => ['1.2345', 12345]] as $code => [$decimal, $minor]) {
        $money = Money::fromDecimal($decimal, Currency::of($code));
        same($minor, $money->minor);
        same($decimal, $money->decimal());
    }
    same('-0.01', Money::fromDecimal('-0.01', Currency::of('USD'))->decimal());
};
$tests['reject malformed and oversized amounts'] = static function (): void {
    foreach (['1e3', ' 1', '+1', '01', '1,00', 'NaN', '1.', '', '<script>'] as $value) {
        rejects('invalid_amount', fn () => Money::fromDecimal($value, Currency::of('USD')));
    }
    rejects('amount_precision', fn () => Money::fromDecimal('1.001', Currency::of('USD')));
    rejects('money_overflow', fn () => Money::fromDecimal('90000000000000.01', Currency::of('USD')));
    rejects('money_overflow', fn () => (new Money(Money::MAX_MINOR, Currency::of('USD')))->add(new Money(1, Currency::of('USD'))));
    rejects('currency_mismatch', fn () => (new Money(1, Currency::of('USD')))->add(new Money(1, Currency::of('EUR'))));
};
$tests['rounding and allocation conserve minor units'] = static function (): void {
    $currency = Currency::of('USD');
    same(1, (new Money(1, $currency))->basisPoints(5000)->minor);
    same(-1, (new Money(-1, $currency))->basisPoints(5000)->minor);
    same(Money::MAX_MINOR, (new Money(Money::MAX_MINOR, $currency))->basisPoints(10000)->minor);
    for ($amount = 0; $amount < 101; ++$amount) {
        $parts = (new Money($amount, $currency))->allocate([1, 2, 0, 3]);
        same($amount, array_sum(array_map(fn (Money $part): int => $part->minor, $parts)));
        same(0, $parts[2]->minor);
    }
};
$tests['unbalanced and mixed-currency journals fail'] = static function (): void {
    $usd = Currency::of('USD');
    rejects('unbalanced_journal', fn () => new Journal($usd, [new JournalLine(null, 'issuance', 'debit', new Money(1, $usd)), new JournalLine(null, 'spending', 'credit', new Money(2, $usd))]));
    rejects('currency_mismatch', fn () => new Journal($usd, [new JournalLine(null, 'issuance', 'debit', new Money(1, $usd)), new JournalLine(null, 'spending', 'credit', new Money(1, Currency::of('EUR')))]));
};
$tests['idempotent account creation and currency isolation'] = static function (): void {
    $e = environment();
    $first = $e['wallet']->account(1, Currency::of('USD'));
    same($first['id'], $e['wallet']->account(1, Currency::of('USD'))['id']);
    $e['wallet']->account(1, Currency::of('EUR'));
    same(2, count($e['query']->accounts()));
    rejects('currency_mismatch', fn () => $e['wallet']->credit($first['id'], new Money(10, Currency::of('EUR')), 'a', 1, 'Test'));
};
$tests['credit debit replay conflict and rollback'] = static function (): void {
    $e = environment();
    $id = $e['wallet']->account(1, Currency::of('USD'))['id'];
    $money = new Money(10000, Currency::of('USD'));
    $first = $e['wallet']->credit($id, $money, 'credit:1', 2, 'Customer service');
    same($first, $e['wallet']->credit($id, $money, 'credit:1', 2, 'Customer service'));
    rejects('idempotency_conflict', fn () => $e['wallet']->credit($id, new Money(10001, $money->currency), 'credit:1', 2, 'Customer service'));
    $e['wallet']->debit($id, new Money(8000, $money->currency), 'debit:1', 2, 'Adjustment');
    rejects('insufficient_funds', fn () => $e['wallet']->debit($id, new Money(8000, $money->currency), 'debit:2', 2, 'Adjustment'));
    same('20.00', $e['query']->balance($id)['available']);
    same(true, $e['reconcile']->account($id)['healthy']);
    same(2, count($e['query']->transactions($id)));
};
$tests['reservation capture refund preserve provenance'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $lot = $e['wallet']->credit($id, new Money(10000, $currency), 'c', 1, 'Promotion', 'promotion', null, $e['clock']->now() + 1000);
    $hold = $e['holds']->reserve($id, new Money(8000, $currency), 'order:1', $e['clock']->now() + 100, 'r', 1);
    same('20.00', $e['query']->balance($id)['available']);
    same('80.00', $e['query']->balance($id)['reserved']);
    rejects('insufficient_funds', fn () => $e['holds']->reserve($id, new Money(8000, $currency), 'order:2', $e['clock']->now() + 100, 'r2', 1));
    $e['holds']->capture($hold['hold_id'], 'cap', 1);
    $e['holds']->capture($hold['hold_id'], 'cap2', 1);
    same('0.00', $e['query']->balance($id)['reserved']);
    $e['refunds']->refund($hold['hold_id'], new Money(3000, $currency), 'refund:1', 2, 'Returned item');
    $e['refunds']->refund($hold['hold_id'], new Money(3000, $currency), 'refund:1', 2, 'Returned item');
    rejects('refund_limit', fn () => $e['refunds']->refund($hold['hold_id'], new Money(6000, $currency), 'refund:2', 2, 'Returned item'));
    same('50.00', $e['query']->balance($id)['available']);
    same('50.00', $e['query']->balance($id)['promotional']);
    $restored = $e['db']->row('SELECT * FROM ' . $e['db']->table('lots') . ' WHERE parent_id=?', [$lot['lot_id']]);
    same('promotion', $restored['source']);
    same(0, (int) $restored['withdrawable']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['release retries and expired provenance'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($id, new Money(1000, $currency), 'c', 1, 'Expiring', 'promotion', null, $e['clock']->now() + 10);
    $hold = $e['holds']->reserve($id, new Money(1000, $currency), 'order:1', $e['clock']->now() + 5, 'r', 1);
    $e['clock']->time += 20;
    rejects('hold_unavailable', fn () => $e['holds']->capture($hold['hold_id'], 'cap', 1));
    $e['holds']->release($hold['hold_id'], 'release', 1);
    $e['holds']->release($hold['hold_id'], 'release:again', 1);
    same('0.00', $e['query']->balance($id)['available']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['earliest expiry and frozen restorative operations'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($id, new Money(1000, $currency), 'cash', 1, 'Cash');
    $promo = $e['wallet']->credit($id, new Money(1000, $currency), 'promo', 1, 'Promotion', 'promotion', null, $e['clock']->now() + 1000);
    $hold = $e['holds']->reserve($id, new Money(1000, $currency), 'order:1', $e['clock']->now() + 100, 'r', 1);
    $remaining = $e['db']->row('SELECT remaining FROM ' . $e['db']->table('lots') . ' WHERE id=?', [$promo['lot_id']]);
    same(0, (int) $remaining['remaining']);
    $e['wallet']->setState($id, WalletState::Frozen, 'freeze', 2, 'Investigation');
    rejects('wallet_unavailable', fn () => $e['holds']->capture($hold['hold_id'], 'cap', 1));
    rejects('wallet_unavailable', fn () => $e['wallet']->debit($id, new Money(100, $currency), 'd', 1, 'Spend'));
    $e['holds']->release($hold['hold_id'], 'release', 1);
    same('20.00', $e['query']->balance($id)['available']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['pending credit matures then expires on distinct stable keys'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($id, new Money(1000, $currency), 'pending', 1, 'Cashback', 'cashback', $e['clock']->now() + 10, $e['clock']->now() + 20);
    same('10.00', $e['query']->balance($id)['pending']);
    rejects('insufficient_funds', fn () => $e['wallet']->debit($id, new Money(100, $currency), 'd', 1, 'Spend'));
    $e['clock']->time += 11;
    same(1, $e['lifecycle']->run()['matured']);
    same('10.00', $e['query']->balance($id)['available']);
    $e['clock']->time += 10;
    same(1, $e['lifecycle']->run()['expired']);
    same('0.00', $e['query']->balance($id)['available']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['refund after expiration does not revive promotional credit'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($id, new Money(1000, $currency), 'c', 1, 'Promotion', 'promotion', null, $e['clock']->now() + 10);
    $hold = $e['holds']->reserve($id, new Money(1000, $currency), 'order', $e['clock']->now() + 5, 'r', 1);
    $e['holds']->capture($hold['hold_id'], 'cap', 1);
    $e['clock']->time += 11;
    $result = $e['refunds']->refund($hold['hold_id'], new Money(1000, $currency), 'ref', 1, 'Refund');
    same('1000', $result['expired_minor']);
    same('0.00', $e['query']->balance($id)['available']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['reconciliation detects projection corruption'] = static function (): void {
    $e = environment();
    $id = $e['wallet']->account(1, Currency::of('USD'))['id'];
    $e['db']->execute('UPDATE ' . $e['db']->table('accounts') . ' SET available=5 WHERE id=?', [$id]);
    same(false, $e['reconcile']->account($id)['healthy']);
    same('active', $e['query']->balance($id)['state']);
    same(true, $e['reconcile']->account($id, true)['frozen']);
    same('frozen', $e['query']->balance($id)['state']);
    same(false, $e['reconcile']->account($id, true)['frozen']);
    same(1, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('audit') . " WHERE action='reconciliation_freeze'")['n']);
};
$tests['live hold cannot spend credit that expired after reservation'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($id, new Money(1000, $currency), 'expiring:seed', 1, 'Promotion', 'promotion', null, $e['clock']->now() + 10);
    $hold = $e['holds']->reserve($id, new Money(1000, $currency), 'long:order', $e['clock']->now() + 100, 'long:reserve', 1);
    $e['clock']->time += 11;
    rejects('hold_unavailable', fn () => $e['holds']->capture($hold['hold_id'], 'long:capture', 1));
    $e['holds']->release($hold['hold_id'], 'long:release', 1);
    same('0.00', $e['query']->balance($id)['available']);
    same('0.00', $e['query']->balance($id)['reserved']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['migration resumes and outbox commits atomically'] = static function (): void {
    $e = environment();
    Schema::migrate($e['db']);
    same(\WalletPlatform\Infrastructure\Database\Schema::VERSION, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('migrations'))['n']);
    $id = $e['wallet']->account(1, Currency::of('USD'))['id'];
    try {
        $e['kernel']->execute('fault', 'fault', [], [$id], 1, 'Fault injection', static function ($context): array {
            $context->audit(array_key_first($context->accounts));
            $context->event('test.failure', []);
            throw new RuntimeException('Simulated failure');
        });
    } catch (RuntimeException $error) {
        same('Simulated failure', $error->getMessage());
    }
    same(1, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('audit'))['n']);
    same(1, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('outbox'))['n']);
    same(0, (int) $e['db']->row('SELECT COUNT(*) AS n FROM ' . $e['db']->table('idempotency'))['n']);
};

$tests['funding settlement and reversal are bound and replay safe'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $topups = new \WalletPlatform\Application\TopUpService($e['wallet']);
    $record = $topups->create($id, new Money(1000, $currency), 'funding:create', 1);
    rejects('topup_conflict', fn () => $topups->complete($record['topup_id'], 42, new Money(1000, $currency)));
    $e['db']->execute('UPDATE ' . $e['db']->table('topups') . " SET order_id=42,state='pending_payment' WHERE id=?", [$record['topup_id']]);
    rejects('topup_conflict', fn () => $topups->complete($record['topup_id'], 42, new Money(1001, $currency)));
    $first = $topups->complete($record['topup_id'], 42, new Money(1000, $currency));
    same($first, $topups->complete($record['topup_id'], 42, new Money(1000, $currency)));
    $topups->reverse($record['topup_id'], new Money(500, $currency), 'refund:funding:1');
    $topups->reverse($record['topup_id'], new Money(500, $currency), 'refund:funding:1');
    same('5.00', $e['query']->balance($id)['available']);
    $e['wallet']->debit($id, new Money(500, $currency), 'spend', 1, 'Spend funded value');
    same('refund_review', $topups->reverse($record['topup_id'], new Money(500, $currency), 'refund:funding:2')['state']);
    same('frozen', $e['query']->balance($id)['state']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['funding recovery preserves closed and suspended accounts'] = static function (): void {
    foreach ([\WalletPlatform\Domain\Wallet\WalletState::Closed, \WalletPlatform\Domain\Wallet\WalletState::Suspended] as $state) {
        $e = environment();
        $currency = Currency::of('USD');
        $id = $e['wallet']->account(1, $currency)['id'];
        $topups = new \WalletPlatform\Application\TopUpService($e['wallet']);
        $record = $topups->create($id, new Money(1000, $currency), 'funding:create', 1);
        $e['db']->execute('UPDATE ' . $e['db']->table('topups') . " SET order_id=42,state='pending_payment' WHERE id=?", [$record['topup_id']]);
        $topups->complete($record['topup_id'], 42, new Money(1000, $currency));
        $e['wallet']->debit($id, new Money(1000, $currency), 'spent', 1, 'Spend funded credit');
        $e['wallet']->setState($id, $state, 'state', 1, 'Restrict account');
        same('refund_review', $topups->reverse($record['topup_id'], new Money(1000, $currency), 'refund:restricted')['state']);
        same($state->value, $e['query']->balance($id)['state']);
        same(true, $e['reconcile']->account($id)['healthy']);
    }
};
$tests['outbox retries do not roll back or duplicate financial effects'] = static function (): void {
    $e = environment();
    $id = $e['wallet']->account(1, Currency::of('USD'))['id'];
    $e['wallet']->credit($id, new Money(1000, Currency::of('USD')), 'credit', 1, 'Credit');
    $seen = [];
    $failure = true;
    $worker = new \WalletPlatform\Application\OutboxProcessor($e['db'], $e['clock'], static function (array $event) use (&$seen, &$failure): void {
        $seen[] = $event['id'];
        if ($failure) {
            $failure = false;
            throw new RuntimeException('Provider unavailable');
        }
    });
    same(1, $worker->run(1)['retried']);
    same('10.00', $e['query']->balance($id)['available']);
    $e['clock']->time += 121;
    same(2, $worker->run()['delivered']);
    same(3, count($seen));
    same(2, count(array_unique($seen)));
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['expired value is excluded from spending display before scheduler runs'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $e['wallet']->credit($id, new Money(1000, $currency), 'promo', 1, 'Promotion', 'promotion', null, $e['clock']->now() + 1);
    $e['clock']->time += 2;
    same('0.00', $e['query']->balance($id)['available']);
    rejects('insufficient_funds', fn () => $e['wallet']->debit($id, new Money(1, $currency), 'spend', 1, 'Spend'));
    same(true, $e['reconcile']->account($id)['healthy']);
};

$tests['worker crashes cannot exceed the delivery attempt limit'] = static function (): void {
    $e = environment();
    $id = $e['wallet']->account(1, Currency::of('USD'))['id'];
    $e['db']->execute('UPDATE ' . $e['db']->table('outbox') . " SET state='processing',attempts=10,lease_until=?", [$e['clock']->now() - 1]);
    $worker = new \WalletPlatform\Application\OutboxProcessor($e['db'], $e['clock'], static function (): void {
        throw new RuntimeException('Exhausted event must not be delivered');
    });
    same(['delivered' => 0, 'retried' => 0, 'failed' => 1], $worker->run());
    same('failed', $e['db']->row('SELECT state FROM ' . $e['db']->table('outbox'))['state']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['liability report equation covers holds maturation refunds and expiration'] = static function (): void {
    $e = environment();
    $currency = Currency::of('USD');
    $id = $e['wallet']->account(1, $currency)['id'];
    $start = $e['clock']->now();
    $e['wallet']->credit($id, new Money(10000, $currency), 'report:opening', 1, 'Opening');
    ++$e['clock']->time;
    $start = $e['clock']->now();
    $e['wallet']->credit($id, new Money(1000, $currency), 'report:promo', 1, 'Promo', 'promotion', $start + 10, $start + 30);
    $hold = $e['holds']->reserve($id, new Money(3000, $currency), 'report:order', $start + 50, 'report:reserve', 1);
    $e['holds']->capture($hold['hold_id'], 'report:capture', 1);
    $e['refunds']->refund($hold['hold_id'], new Money(1000, $currency), 'report:refund', 1, 'Return');
    $e['clock']->time += 11;
    $e['lifecycle']->run();
    $e['clock']->time += 20;
    $e['lifecycle']->run();
    $report = (new \WalletPlatform\Application\ReportService($e['db']))->liability($start, $e['clock']->now() + 1)[0];
    same('10000', $report['opening']);
    same('2000', $report['credits']);
    same('3000', $report['debits']);
    same('1000', $report['expiration']);
    same('8000', $report['closing']);
    same((int) $report['closing'], (int) $report['opening'] + (int) $report['credits'] - (int) $report['debits'] - (int) $report['expiration']);
    same(true, $e['reconcile']->account($id)['healthy']);
};
$tests['CSV export neutralizes formulas and preserves quoted fields'] = static function (): void {
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@SUM(A1)', "\t=1+1", " \r=1+1"] as $value) {
        same("'" . $value, \WalletPlatform\Infrastructure\Csv::cell($value));
    }
    same('USD 10.00', \WalletPlatform\Infrastructure\Csv::cell('USD 10.00'));
    $stream = fopen('php://temp', 'w+');
    \WalletPlatform\Infrastructure\Csv::row($stream, ['="attack"', 'comma, and "quote"']);
    rewind($stream);
    same(["'=\"attack\"", 'comma, and "quote"'], fgetcsv($stream, null, ',', '"', ''));
    fclose($stream);
};

$tests += require __DIR__ . '/split-cases.php';
$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failures;
        fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n{$error->getTraceAsString()}\n");
    }
}
echo count($tests) . " tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
