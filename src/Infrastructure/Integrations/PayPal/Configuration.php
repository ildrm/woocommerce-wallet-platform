<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Integrations\PayPal;

use WalletPlatform\Domain\Shared\WalletException;

final readonly class Configuration implements \JsonSerializable
{
    public function __construct(public bool $enabled, public string $environment, public string $clientId, #[\SensitiveParameter] public string $clientSecret, public string $merchantId, public string $webhookId, public bool $vaultEligible = false)
    {
        if (!in_array($environment, ['sandbox', 'live'], true)) {
            throw new WalletException('provider_configuration', 'PayPal environment must be sandbox or live.');
        }
        if ($enabled && (!preg_match('/^[A-Za-z0-9_-]{10,255}$/D', $clientId) || !preg_match('/^[A-Za-z0-9_-]{10,255}$/D', $clientSecret) || !preg_match('/^[A-Z0-9]{8,32}$/D', $merchantId) || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $webhookId))) {
            throw new WalletException('provider_configuration', 'Enabled PayPal requires valid server-managed credentials and merchant binding.');
        }
    }

    public function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new WalletException('provider_disabled', 'PayPal is disabled until configured and verified.');
        }
    }

    public function baseUrl(): string
    {
        return $this->environment === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function __debugInfo(): array
    {
        return ['enabled' => $this->enabled, 'environment' => $this->environment, 'credentials' => '[redacted]', 'vault_eligible' => $this->vaultEligible];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Provider credentials must remain in server-managed configuration.');
    }
}
