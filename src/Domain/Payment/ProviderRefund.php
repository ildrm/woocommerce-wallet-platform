<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Payment;

final readonly class ProviderRefund
{
    public function __construct(public string $id, public string $status)
    {
    }

    public function settled(): bool
    {
        return $this->status === 'COMPLETED';
    }
}
