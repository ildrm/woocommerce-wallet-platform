<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure;

use WalletPlatform\Application\Port\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
