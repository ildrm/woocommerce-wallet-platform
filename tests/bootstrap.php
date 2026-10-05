<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/autoload.php';

use WalletPlatform\Application\CreditLifecycle;
use WalletPlatform\Application\FinancialKernel;
use WalletPlatform\Application\HoldService;
use WalletPlatform\Application\LotAllocator;
use WalletPlatform\Application\Policies\Limits;
use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Application\QueryService;
use WalletPlatform\Application\ReconciliationService;
use WalletPlatform\Application\RefundService;
use WalletPlatform\Application\WalletService;
use WalletPlatform\Infrastructure\Database\PdoDatabase;
use WalletPlatform\Infrastructure\Database\Schema;

final class TestClock implements Clock
{
    public function __construct(public int $time = 1800000000)
    {
    }

    public function now(): int
    {
        return $this->time;
    }
}

function environment(?string $dsn = null, string $prefix = 't_'): array
{
    if ($dsn === null && getenv('WALLET_TEST_DSN')) {
        $dsn = getenv('WALLET_TEST_DSN');
        $prefix = 't_' . bin2hex(random_bytes(5)) . '_';
    }
    $pdo = new PDO($dsn ?? 'sqlite::memory:', getenv('WALLET_TEST_USER') ?: null, getenv('WALLET_TEST_PASSWORD') ?: null);
    $db = new PdoDatabase($pdo, $prefix);
    Schema::migrate($db);
    $clock = new TestClock();
    $kernel = new FinancialKernel($db, $clock);
    $lots = new LotAllocator();
    $limits = new Limits();
    $wallet = new WalletService($kernel, $lots, $limits);
    $holds = new HoldService($wallet, $lots, $limits);
    return ['pdo' => $pdo, 'db' => $db, 'clock' => $clock, 'kernel' => $kernel, 'lots' => $lots, 'wallet' => $wallet, 'holds' => $holds, 'refunds' => new RefundService($wallet, $holds, $lots), 'query' => new QueryService($db, $clock), 'reconcile' => new ReconciliationService($db, $clock), 'lifecycle' => new CreditLifecycle($kernel, $holds)];
}

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . '; received ' . var_export($actual, true));
    }
}

function rejects(string $code, callable $work): void
{
    try {
        $work();
    } catch (WalletPlatform\Domain\Shared\WalletException $error) {
        same($code, $error->errorCode);
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}
