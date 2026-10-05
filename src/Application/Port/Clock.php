<?php

declare(strict_types=1);

namespace WalletPlatform\Application\Port;

interface Clock
{
    public function now(): int;
}
