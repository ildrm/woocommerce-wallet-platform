<?php

declare(strict_types=1);

namespace WalletPlatform\Bootstrap;

use WalletPlatform\Application\CreditLifecycle;
use WalletPlatform\Application\FinancialKernel;
use WalletPlatform\Application\HoldService;
use WalletPlatform\Application\LotAllocator;
use WalletPlatform\Application\Policies\Limits;
use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Application\Port\Database;
use WalletPlatform\Application\QueryService;
use WalletPlatform\Application\ReconciliationService;
use WalletPlatform\Application\RefundService;
use WalletPlatform\Application\WalletService;
use WalletPlatform\Application\TopUpService;

final class Services
{
    public readonly WalletService $wallets;
    public readonly HoldService $holds;
    public readonly RefundService $refunds;
    public readonly QueryService $queries;
    public readonly ReconciliationService $reconciliation;
    public readonly CreditLifecycle $lifecycle;
    public readonly TopUpService $topups;

    public function __construct(public readonly Database $db, public readonly Clock $clock, Limits $limits)
    {
        $kernel = new FinancialKernel($db, $clock);
        $lots = new LotAllocator();
        $this->wallets = new WalletService($kernel, $lots, $limits);
        $this->holds = new HoldService($this->wallets, $lots, $limits);
        $this->refunds = new RefundService($this->wallets, $this->holds, $lots);
        $this->queries = new QueryService($db, $clock);
        $this->reconciliation = new ReconciliationService($db, $clock);
        $this->lifecycle = new CreditLifecycle($kernel, $this->holds);
        $this->topups = new TopUpService($this->wallets);
    }
}
