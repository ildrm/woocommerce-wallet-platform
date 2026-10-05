<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Integrations\PayPal;

use WalletPlatform\Application\Port\Clock;

/** Credentials stay in server configuration, outside WordPress options, REST payloads, and diagnostics. */
final class Factory
{
    public static function configuration(): Configuration
    {
        $value = static fn (string $name, mixed $default): mixed => defined($name) ? constant($name) : $default;
        return new Configuration($value('WALLET_PAYPAL_ENABLED', false) === true, (string) $value('WALLET_PAYPAL_ENVIRONMENT', 'sandbox'), (string) $value('WALLET_PAYPAL_CLIENT_ID', ''), (string) $value('WALLET_PAYPAL_CLIENT_SECRET', ''), (string) $value('WALLET_PAYPAL_MERCHANT_ID', ''), (string) $value('WALLET_PAYPAL_WEBHOOK_ID', ''), $value('WALLET_PAYPAL_VAULT_ELIGIBLE', false) === true);
    }

    public static function create(Clock $clock): PayPalProvider
    {
        return new PayPalProvider(self::configuration(), new WordPressTransport(), $clock);
    }
}
