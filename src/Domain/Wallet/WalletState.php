<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Wallet;

use WalletPlatform\Domain\Shared\WalletException;

enum WalletState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Frozen = 'frozen';
    case Suspended = 'suspended';
    case Closed = 'closed';

    public function assertAllows(string $operation): void
    {
        if (in_array($operation, ['refund', 'release', 'expire', 'reconcile'], true)) {
            return;
        }
        if ($this !== self::Active && !($this === self::Pending && $operation === 'admin_credit')) {
            throw new WalletException('wallet_unavailable', 'Wallet state does not permit this operation.');
        }
    }
}
