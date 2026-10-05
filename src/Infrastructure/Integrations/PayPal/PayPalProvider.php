<?php

declare(strict_types=1);

namespace WalletPlatform\Infrastructure\Integrations\PayPal;

use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Application\Port\HttpTransport;
use WalletPlatform\Application\Port\PaymentProvider;
use WalletPlatform\Domain\Payment\PaymentIntent;
use WalletPlatform\Domain\Payment\ProviderOrder;
use WalletPlatform\Domain\Payment\ProviderRefund;
use WalletPlatform\Domain\Payment\SavedPayment;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class PayPalProvider implements PaymentProvider
{
    public const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD'];
    public const WHOLE_UNIT_CURRENCIES = ['HUF', 'JPY', 'TWD'];
    private ?string $accessToken = null;
    private int $tokenExpires = 0;

    public function __construct(private readonly Configuration $config, private readonly HttpTransport $http, private readonly Clock $clock)
    {
    }

    public function create(PaymentIntent $intent, string $returnUrl, string $cancelUrl): ProviderOrder
    {
        $this->httpsUrl($returnUrl);
        $this->httpsUrl($cancelUrl);
        $payload = $this->payload($intent);
        $payload['payment_source'] = ['paypal' => ['experience_context' => ['return_url' => $returnUrl, 'cancel_url' => $cancelUrl, 'user_action' => 'PAY_NOW']]];
        $created = $this->api('POST', '/v2/checkout/orders', $payload, $intent->requestId('paypal:create'));
        return $this->inspect((string) ($created['id'] ?? ''), $intent);
    }

    public function supports(Money $amount): bool
    {
        try {
            $amount->positive();
            $this->amount($amount);
            return true;
        } catch (WalletException $error) {
            return false;
        }
    }

    public function inspect(string $orderId, PaymentIntent $intent): ProviderOrder
    {
        $this->resourceId($orderId);
        return $this->order($this->api('GET', '/v2/checkout/orders/' . $orderId), $intent, $orderId);
    }

    public function capture(string $orderId, PaymentIntent $intent): ProviderOrder
    {
        $current = $this->inspect($orderId, $intent);
        if ($current->settled() || $current->captureId !== null) {
            return $current; // Pending captures are inspected, never captured again.
        }
        if ($current->status !== 'APPROVED') {
            throw new WalletException('provider_approval_required', 'PayPal order has not been approved.');
        }
        $result = $this->api('POST', '/v2/checkout/orders/' . $orderId . '/capture', null, $intent->requestId('paypal:capture'));
        if (($result['id'] ?? '') !== $orderId) {
            throw new WalletException('provider_response_mismatch', 'PayPal capture returned another order.');
        }
        // Capture responses omit some order fields; inspect the full order instead of weakening binding checks.
        return $this->inspect($orderId, $intent);
    }

    public function refund(string $captureId, Money $amount, string $refundReference): ProviderRefund
    {
        $this->resourceId($captureId);
        $amount->positive();
        if (!preg_match('/^[a-f0-9]{32}$/D', $refundReference)) {
            throw new WalletException('invalid_reference', 'Refund requires a persisted reference.');
        }
        $result = $this->api('POST', '/v2/payments/captures/' . $captureId . '/refund', ['amount' => $this->amount($amount), 'invoice_id' => $refundReference], substr(hash('sha256', 'paypal:refund:' . $refundReference), 0, 32));
        $this->resourceId((string) ($result['id'] ?? ''));
        $this->assertAmount($result['amount'] ?? [], $amount);
        if (!in_array($result['status'] ?? '', ['COMPLETED', 'PENDING', 'FAILED', 'CANCELLED'], true) || ($result['invoice_id'] ?? '') !== $refundReference) {
            throw new WalletException('provider_response_mismatch', 'PayPal refund binding is invalid.');
        }
        return new ProviderRefund($result['id'], $result['status']);
    }

    public function chargeSaved(PaymentIntent $intent, SavedPayment $payment): ProviderOrder
    {
        $this->config->assertEnabled();
        if (!$this->config->vaultEligible || $intent->ownerId !== $payment->ownerId) {
            throw new WalletException('saved_payment_unavailable', 'Saved payment requires merchant eligibility and owner-bound consent.');
        }
        $token = $this->api('GET', '/v3/vault/payment-tokens/' . rawurlencode($payment->tokenId));
        if (($token['id'] ?? '') !== $payment->tokenId || ($token['customer']['id'] ?? '') !== $payment->customerId || !isset($token['payment_source']['paypal'])) {
            throw new WalletException('saved_payment_mismatch', 'PayPal saved token is not bound to the expected customer.');
        }
        $payload = $this->payload($intent);
        $payload['payment_source'] = ['paypal' => ['vault_id' => $payment->tokenId, 'stored_credential' => ['payment_initiator' => 'MERCHANT', 'usage' => 'SUBSEQUENT', 'usage_pattern' => 'UNSCHEDULED_PREPAID']]];
        // Single-step merchant-initiated order. Approval contingencies remain pending; no blind second capture.
        $created = $this->api('POST', '/v2/checkout/orders', $payload, $intent->requestId('paypal:saved'));
        return $this->inspect((string) ($created['id'] ?? ''), $intent);
    }

    public function inspectRefund(string $refundId, string $captureId, Money $amount, string $refundReference): ProviderRefund
    {
        $this->resourceId($refundId);
        $this->resourceId($captureId);
        $result = $this->api('GET', '/v2/payments/refunds/' . $refundId);
        $this->assertAmount($result['amount'] ?? [], $amount);
        $parent = false;
        foreach ($result['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'up' && ($link['method'] ?? '') === 'GET') {
                $url = $this->httpsUrl((string) ($link['href'] ?? ''));
                $hosts = $this->config->environment === 'live' ? ['api.paypal.com', 'api-m.paypal.com'] : ['api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'];
                $parent = in_array($url['host'], $hosts, true) && ($url['path'] ?? '') === '/v2/payments/captures/' . $captureId && !isset($url['query']) && !isset($url['fragment']);
            }
        }
        if (!$parent || ($result['id'] ?? '') !== $refundId || ($result['invoice_id'] ?? '') !== $refundReference || !in_array($result['status'] ?? '', ['COMPLETED', 'PENDING', 'FAILED', 'CANCELLED'], true)) {
            throw new WalletException('provider_response_mismatch', 'PayPal refund does not match its persisted intent.');
        }
        return new ProviderRefund($refundId, $result['status']);
    }

    public function verifyWebhook(string $rawBody, array $headers): array
    {
        $this->config->assertEnabled();
        if (strlen($rawBody) > 1048576 || $rawBody === '') {
            throw new WalletException('invalid_webhook', 'Webhook body size is invalid.');
        }
        $event = $this->decode($rawBody);
        $fields = ['auth_algo' => 'paypal-auth-algo', 'cert_url' => 'paypal-cert-url', 'transmission_id' => 'paypal-transmission-id', 'transmission_sig' => 'paypal-transmission-sig', 'transmission_time' => 'paypal-transmission-time'];
        $payload = ['webhook_id' => $this->config->webhookId];
        foreach ($fields as $field => $header) {
            $value = $headers[$header] ?? '';
            if (!is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new WalletException('invalid_webhook', 'Required webhook headers are invalid.');
            }
            $payload[$field] = $value;
        }
        $certificate = parse_url($payload['cert_url']);
        $allowed = $this->config->environment === 'live' ? ['api.paypal.com', 'api-m.paypal.com'] : ['api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'];
        if (($certificate['scheme'] ?? '') !== 'https' || !in_array($certificate['host'] ?? '', $allowed, true) || isset($certificate['user']) || isset($certificate['pass']) || isset($certificate['port']) || isset($certificate['query']) || isset($certificate['fragment']) || !preg_match('~^/v1/notifications/certs/[A-Za-z0-9_-]+$~D', $certificate['path'] ?? '')) {
            throw new WalletException('invalid_webhook', 'Webhook certificate URL is not trusted.');
        }
        $timestamp = strtotime($payload['transmission_time']);
        if ($timestamp === false || abs($this->clock->now() - $timestamp) > 259200 || $payload['auth_algo'] !== 'SHA256withRSA' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', (string) ($event['id'] ?? ''))) {
            throw new WalletException('invalid_webhook', 'Webhook transmission is invalid or stale.');
        }
        // Embed the raw JSON exactly: PayPal postback verification is sensitive to re-serialization.
        $body = substr(json_encode($payload, JSON_THROW_ON_ERROR), 0, -1) . ',"webhook_event":' . $rawBody . '}';
        $verified = $this->send('POST', '/v1/notifications/verify-webhook-signature', $body);
        if (($verified['verification_status'] ?? '') !== 'SUCCESS') {
            throw new WalletException('webhook_signature_invalid', 'PayPal webhook signature was rejected.');
        }
        return $event; // Consumers must durably deduplicate event ID and inspect the bound provider resource.
    }

    private function payload(PaymentIntent $intent): array
    {
        return ['intent' => 'CAPTURE', 'purchase_units' => [['reference_id' => $intent->id, 'custom_id' => $intent->id, 'invoice_id' => $intent->id, 'payee' => ['merchant_id' => $this->config->merchantId], 'amount' => $this->amount($intent->amount)]]];
    }

    private function amount(Money $money): array
    {
        if (!in_array($money->currency->code, self::CURRENCIES, true) || !in_array($money->currency->exponent, [0, 2], true)) {
            throw new WalletException('provider_currency_unsupported', 'Currency is not supported by this PayPal adapter.');
        }
        $value = $money->decimal();
        if (in_array($money->currency->code, self::WHOLE_UNIT_CURRENCIES, true)) {
            $scale = 10 ** $money->currency->exponent;
            if ($money->minor % $scale !== 0) {
                throw new WalletException('provider_precision_unsupported', 'PayPal requires whole units for this currency.');
            }
            $value = (string) intdiv($money->minor, $scale);
        }
        return ['currency_code' => $money->currency->code, 'value' => $value];
    }

    private function assertAmount(array $data, Money $expected): void
    {
        if (($data['currency_code'] ?? '') !== $expected->currency->code || !is_string($data['value'] ?? null) || Money::fromDecimal($data['value'], $expected->currency)->compare($expected) !== 0) {
            throw new WalletException('provider_response_mismatch', 'PayPal amount or currency differs from the immutable intent.');
        }
    }

    private function order(array $data, PaymentIntent $intent, ?string $expectedId = null): ProviderOrder
    {
        $id = (string) ($data['id'] ?? '');
        $this->resourceId($id);
        $units = $data['purchase_units'] ?? [];
        if (($expectedId !== null && $id !== $expectedId) || ($data['intent'] ?? '') !== 'CAPTURE' || !is_array($units) || count($units) !== 1 || !isset($units[0]) || ($units[0]['reference_id'] ?? '') !== $intent->id || ($units[0]['custom_id'] ?? '') !== $intent->id || ($units[0]['payee']['merchant_id'] ?? '') !== $this->config->merchantId || !in_array($data['status'] ?? '', ['CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED'], true)) {
            throw new WalletException('provider_response_mismatch', 'PayPal order reference or merchant binding is invalid.');
        }
        $this->assertAmount($units[0]['amount'] ?? [], $intent->amount);
        $captures = $units[0]['payments']['captures'] ?? [];
        if (!is_array($captures) || count($captures) > 1) {
            throw new WalletException('provider_response_mismatch', 'PayPal order contains unexpected captures.');
        }
        $captureId = null;
        $captureStatus = null;
        if ($captures !== []) {
            $captureId = (string) ($captures[0]['id'] ?? '');
            $this->resourceId($captureId);
            $this->assertAmount($captures[0]['amount'] ?? [], $intent->amount);
            $captureStatus = $captures[0]['status'] ?? '';
            if (!in_array($captureStatus, ['COMPLETED', 'PENDING', 'DECLINED', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true) || ($captures[0]['final_capture'] ?? null) !== true) {
                throw new WalletException('provider_response_mismatch', 'PayPal capture state is invalid.');
            }
        }
        $approval = null;
        foreach ($data['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                $approval = (string) ($link['href'] ?? '');
                $url = $this->httpsUrl($approval);
                $host = $this->config->environment === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
                parse_str($url['query'] ?? '', $query);
                if (($url['host'] ?? '') !== $host || ($url['path'] ?? '') !== '/checkoutnow' || ($query['token'] ?? '') !== $id) {
                    throw new WalletException('provider_response_mismatch', 'PayPal approval redirect is invalid.');
                }
            }
        }
        return new ProviderOrder($id, $data['status'], $approval, $captureId, $captureStatus);
    }

    private function api(string $method, string $path, ?array $payload = null, ?string $requestId = null): array
    {
        return $this->send($method, $path, $payload === null ? ($method === 'GET' ? '' : '{}') : json_encode($payload, JSON_THROW_ON_ERROR), $requestId);
    }

    private function send(string $method, string $path, #[\SensitiveParameter] string $body, ?string $requestId = null): array
    {
        $this->config->assertEnabled();
        $headers = ['Authorization' => 'Bearer ' . $this->token(), 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Prefer' => 'return=representation'];
        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = $requestId;
        }
        $response = $this->http->request($method, $this->config->baseUrl() . $path, $headers, $body);
        if ($response->status < 200 || $response->status >= 300) {
            throw new WalletException($response->status >= 500 || $response->status === 429 ? 'provider_outcome_unknown' : 'provider_rejected', 'PayPal request was not confirmed. Inspect the original reference before retrying.');
        }
        return $this->decode($response->body);
    }

    private function token(): string
    {
        if ($this->accessToken !== null && $this->tokenExpires > $this->clock->now()) {
            return $this->accessToken;
        }
        $response = $this->http->request('POST', $this->config->baseUrl() . '/v1/oauth2/token', ['Authorization' => 'Basic ' . base64_encode($this->config->clientId . ':' . $this->config->clientSecret), 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], 'grant_type=client_credentials');
        if ($response->status !== 200) {
            throw new WalletException('provider_authentication', 'PayPal authentication failed.');
        }
        $data = $this->decode($response->body);
        if (($data['token_type'] ?? '') !== 'Bearer' || !is_string($data['access_token'] ?? null) || !preg_match('/^[A-Za-z0-9._~-]{10,4096}$/D', $data['access_token']) || !is_int($data['expires_in'] ?? null) || $data['expires_in'] < 60 || $data['expires_in'] > 86400) {
            throw new WalletException('provider_authentication', 'PayPal token response is invalid.');
        }
        $this->accessToken = $data['access_token'];
        $this->tokenExpires = $this->clock->now() + $data['expires_in'] - 30;
        return $this->accessToken;
    }

    private function decode(string $body): array
    {
        if (strlen($body) > 1048576) {
            throw new WalletException('provider_response_invalid', 'PayPal response exceeds the permitted size.');
        }
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $error) {
            throw new WalletException('provider_response_invalid', 'PayPal response is invalid JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new WalletException('provider_response_invalid', 'PayPal response must be a JSON object.');
        }
        return $data;
    }

    private function resourceId(string $id): void
    {
        if (!preg_match('/^[A-Z0-9]{10,64}$/D', $id)) {
            throw new WalletException('invalid_provider_reference', 'PayPal resource ID is invalid.');
        }
    }

    private function httpsUrl(string $value): array
    {
        $url = parse_url($value);
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['port']) || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new WalletException('invalid_redirect', 'Payment redirects require an absolute HTTPS URL.');
        }
        return $url;
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'paypal', 'environment' => $this->config->environment, 'enabled' => $this->config->enabled];
    }
}
