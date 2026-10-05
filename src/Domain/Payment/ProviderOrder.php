<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Payment;

/** A verified provider resource. COMPLETED alone is insufficient: capture status must also be COMPLETED. */
final readonly class ProviderOrder
{
    public function __construct(public string $id, public string $status, public ?string $approvalUrl, public ?string $captureId, public ?string $captureStatus)
    {
    }

    public function settled(): bool
    {
        return $this->status === 'COMPLETED' && $this->captureStatus === 'COMPLETED' && $this->captureId !== null;
    }
}
