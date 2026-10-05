<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;

function parallel(array $commands): array
{
    $processes = [];
    foreach ($commands as $command) {
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/parallel-worker.php'], $command), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start test worker.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException('Worker failed: ' . $errors);
        }
        $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
    return $results;
}

$dsn = getenv('WALLET_TEST_DSN');
if (!$dsn) {
    $file = sys_get_temp_dir() . '/wallet-concurrency-' . bin2hex(random_bytes(6)) . '.sqlite';
    $dsn = 'sqlite:' . $file;
    putenv('WALLET_TEST_DSN=' . $dsn);
}
$prefix = 'c_' . bin2hex(random_bytes(5)) . '_';
$e = environment($dsn, $prefix);
$currency = Currency::of('USD');
$id = $e['wallet']->account(1, $currency)['id'];
$e['wallet']->credit($id, new Money(10000, $currency), 'initial', 1, 'Seed test');
$results = parallel([[$prefix, $id, 'debit', 'spend:1'], [$prefix, $id, 'debit', 'spend:2']]);
same(1, count(array_filter($results, static fn ($row): bool => $row['ok'])));
same('20.00', $e['query']->balance($id)['available']);
same(true, $e['reconcile']->account($id)['healthy']);
echo "PASS simultaneous 80 spends from 100; one economic effect\n";
$results = parallel(array_fill(0, 5, [$prefix, $id, 'credit', 'callback:one']));
same(5, count(array_filter($results, static fn ($row): bool => $row['ok'])));
same(1, count(array_unique(array_column(array_column($results, 'result'), 'entry_id'))));
same('30.00', $e['query']->balance($id)['available']);
echo "PASS five duplicate callbacks; original result returned\n";
$e['wallet']->credit($id, new Money(8000, $currency), 'more', 1, 'Seed refund test');
$hold = $e['holds']->reserve($id, new Money(8000, $currency), 'order:parallel', $e['clock']->now() + 100, 'reserve', 1);
$e['holds']->capture($hold['hold_id'], 'capture', 1);
$results = parallel([[$prefix, $hold['hold_id'], 'refund', 'refund:1'], [$prefix, $hold['hold_id'], 'refund', 'refund:2']]);
same(1, count(array_filter($results, static fn ($row): bool => $row['ok'])));
same('90.00', $e['query']->balance($id)['available']);
same(true, $e['reconcile']->account($id)['healthy']);
echo 'PASS concurrent over-refunds rejected; ' . $e['db']->driver() . " reconciliation healthy\n";
if (isset($file)) {
    unlink($file);
}
