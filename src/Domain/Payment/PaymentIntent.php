<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Payment;

use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final readonly class PaymentIntent
{
    public function __construct(public string $id, public int $ownerId, public Money $amount)
    {
        $amount->positive();
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $ownerId <= 0) {
            throw new WalletException('invalid_payment_intent', 'Payment requires a persisted reference and owner.');
        }
    }

    public function requestId(string $operation): string
    {
        return substr(hash('sha256', $operation . ':' . $this->id), 0, 32);
    }
}
