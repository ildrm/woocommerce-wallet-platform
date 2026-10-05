<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

$e = environment(getenv('WALLET_TEST_DSN') ?: null, $argv[1]);
try {
    $currency = Currency::of('USD');
    $result = match ($argv[3]) {
        'debit' => $e['wallet']->debit($argv[2], new Money(8000, $currency), $argv[4], 1, 'Parallel spend'),
        'credit' => $e['wallet']->credit($argv[2], new Money(1000, $currency), $argv[4], 1, 'Parallel credit'),
        'refund' => $e['refunds']->refund($argv[2], new Money(6000, $currency), $argv[4], 1, 'Parallel refund'),
        default => throw new RuntimeException('Invalid worker operation'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (WalletException $error) {
    echo json_encode(['ok' => false, 'code' => $error->errorCode], JSON_THROW_ON_ERROR);
}
