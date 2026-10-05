<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Payment;

use WalletPlatform\Domain\Shared\WalletException;

/** Construct only from a server-owned consent record, never from browser-submitted token IDs. */
final readonly class SavedPayment implements \JsonSerializable
{
    public function __construct(public int $ownerId, public string $customerId, #[\SensitiveParameter] public string $tokenId, public string $consentId)
    {
        if ($ownerId <= 0 || !preg_match('/^[A-Za-z0-9_-]{1,22}$/D', $customerId) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $tokenId) || !preg_match('/^[a-f0-9]{32}$/D', $consentId)) {
            throw new WalletException('invalid_saved_payment', 'Saved payment requires owner-bound consent.');
        }
    }

    public function __debugInfo(): array
    {
        return ['owner_id' => $this->ownerId, 'token' => '[redacted]', 'consent_id' => $this->consentId];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Saved payment credentials require explicit encrypted persistence.');
    }
}
