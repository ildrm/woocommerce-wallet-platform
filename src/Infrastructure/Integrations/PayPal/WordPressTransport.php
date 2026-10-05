<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Integrations\PayPal;

use WalletPlatform\Application\Port\HttpTransport;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Application\DTO\HttpResponse;

final class WordPressTransport implements HttpTransport
{
    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body): HttpResponse
    {
        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? '') !== 'https' || !in_array($parsed['host'] ?? '', ['api-m.paypal.com', 'api-m.sandbox.paypal.com'], true) || isset($parsed['port']) || isset($parsed['user']) || isset($parsed['pass']) || !in_array($method, ['GET', 'POST'], true)) {
            throw new WalletException('provider_endpoint_invalid', 'PayPal transport rejects unknown endpoints.');
        }
        $response = wp_remote_request($url, ['method' => $method, 'headers' => $headers, 'body' => $body, 'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048577]);
        if (is_wp_error($response)) {
            throw new WalletException('provider_outcome_unknown', 'PayPal network outcome is unknown. Inspect the original reference before retrying.');
        }
        return new HttpResponse(wp_remote_retrieve_response_code($response), wp_remote_retrieve_body($response));
    }
}
