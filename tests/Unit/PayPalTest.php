<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WalletPlatform\Application\DTO\HttpResponse;
use WalletPlatform\Application\Port\Clock;
use WalletPlatform\Application\Port\HttpTransport;
use WalletPlatform\Domain\Payment\PaymentIntent;
use WalletPlatform\Domain\Payment\SavedPayment;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;
use WalletPlatform\Infrastructure\Integrations\PayPal\Configuration;
use WalletPlatform\Infrastructure\Integrations\PayPal\PayPalProvider;

final class PayPalTest extends TestCase
{
    private const ORDER = '5O190127TN364715T';
    private const CAPTURE = '3C679366HH908993F';
    private const MERCHANT = 'QDGTZ7B92B9QT';
    private const REFERENCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function fixture(bool $enabled = true, bool $vault = false): array
    {
        $clock = new class implements Clock {
            public function now(): int { return 1800000000; }
        };
        $http = new class implements HttpTransport {
            public array $responses = [];
            public array $requests = [];
            public function request(string $method, string $url, array $headers, string $body): HttpResponse
            {
                $this->requests[] = compact('method', 'url', 'headers', 'body');
                if ($this->responses === []) { throw new RuntimeException('Unexpected HTTP call'); }
                return array_shift($this->responses);
            }
        };
        $provider = new PayPalProvider(new Configuration($enabled, 'sandbox', 'test_client_id_abc', 'test_secret_id_abc', self::MERCHANT, 'test_webhook_id_abc', $vault), $http, $clock);
        $intent = new PaymentIntent(self::REFERENCE, 42, new Money(2500, Currency::of('USD')));
        return [$provider, $http, $intent, $clock];
    }

    private function response(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function token(): HttpResponse
    {
        return $this->response(['token_type' => 'Bearer', 'access_token' => 'test_access_token_abc', 'expires_in' => 3600]);
    }

    private function order(string $status = 'CREATED', ?string $captureStatus = null): array
    {
        $unit = ['reference_id' => self::REFERENCE, 'custom_id' => self::REFERENCE, 'payee' => ['merchant_id' => self::MERCHANT], 'amount' => ['currency_code' => 'USD', 'value' => '25.00']];
        if ($captureStatus !== null) {
            $unit['payments'] = ['captures' => [['id' => self::CAPTURE, 'status' => $captureStatus, 'amount' => ['currency_code' => 'USD', 'value' => '25.00'], 'final_capture' => true]]];
        }
        return ['id' => self::ORDER, 'intent' => 'CAPTURE', 'status' => $status, 'purchase_units' => [$unit], 'links' => $status === 'CREATED' ? [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . self::ORDER]] : []];
    }

    public function testDisabledProviderDoesNotCallNetwork(): void
    {
        [$provider, $http, $intent] = $this->fixture(false);
        try { $provider->create($intent, 'https://merchant.invalid/return', 'https://merchant.invalid/cancel'); self::fail('Disabled provider charged'); }
        catch (WalletException $error) { self::assertSame('provider_disabled', $error->errorCode); }
        self::assertSame([], $http->requests);
    }

    public function testCreationUsesCanonicalMoneyStableReferenceAndThenInspectsBinding(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $http->responses = [$this->token(), $this->response(['id' => self::ORDER], 201), $this->response($this->order())];
        $result = $provider->create($intent, 'https://merchant.invalid/return', 'https://merchant.invalid/cancel');
        self::assertFalse($result->settled());
        self::assertSame('https://www.sandbox.paypal.com/checkoutnow?token=' . self::ORDER, $result->approvalUrl);
        $request = $http->requests[1];
        $body = json_decode($request['body'], true);
        self::assertSame(['currency_code' => 'USD', 'value' => '25.00'], $body['purchase_units'][0]['amount']);
        self::assertSame(self::MERCHANT, $body['purchase_units'][0]['payee']['merchant_id']);
        self::assertSame($intent->requestId('paypal:create'), $request['headers']['PayPal-Request-Id']);
        self::assertSame('GET', $http->requests[2]['method']);
    }

    public function testCaptureInspectsBeforeAndAfterAndReplayDoesNotCaptureAgain(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $http->responses = [$this->token(), $this->response($this->order('APPROVED')), $this->response(['id' => self::ORDER, 'status' => 'COMPLETED'], 201), $this->response($this->order('COMPLETED', 'COMPLETED')), $this->response($this->order('COMPLETED', 'COMPLETED'))];
        self::assertTrue($provider->capture(self::ORDER, $intent)->settled());
        self::assertTrue($provider->capture(self::ORDER, $intent)->settled());
        self::assertSame($intent->requestId('paypal:capture'), $http->requests[2]['headers']['PayPal-Request-Id']);
        self::assertCount(1, array_filter($http->requests, fn ($request) => str_ends_with($request['url'], '/capture')));
    }

    public function testPendingCaptureIsNotSettlementAndIsNeverRecaptured(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $http->responses = [$this->token(), $this->response($this->order('COMPLETED', 'PENDING'))];
        self::assertFalse($provider->capture(self::ORDER, $intent)->settled());
        self::assertCount(2, $http->requests);
    }

    public function testRejectsWrongMerchantAmountCurrencyReferenceAndRedirect(): void
    {
        foreach (['merchant', 'amount', 'currency', 'reference', 'redirect', 'capture_amount'] as $attack) {
            [$provider, $http, $intent] = $this->fixture();
            $data = $this->order('CREATED', 'COMPLETED');
            if ($attack === 'merchant') { $data['purchase_units'][0]['payee']['merchant_id'] = 'OTHER00000000'; }
            if ($attack === 'amount') { $data['purchase_units'][0]['amount']['value'] = '25.01'; }
            if ($attack === 'currency') { $data['purchase_units'][0]['amount']['currency_code'] = 'EUR'; }
            if ($attack === 'reference') { $data['purchase_units'][0]['reference_id'] = str_repeat('b', 32); }
            if ($attack === 'redirect') { $data['links'][0]['href'] = 'https://attacker.invalid/checkoutnow?token=' . self::ORDER; }
            if ($attack === 'capture_amount') { $data['purchase_units'][0]['payments']['captures'][0]['amount']['value'] = '0.25'; }
            $http->responses = [$this->token(), $this->response($data)];
            try { $provider->inspect(self::ORDER, $intent); self::fail('Accepted attack: ' . $attack); }
            catch (WalletException $error) { self::assertSame('provider_response_mismatch', $error->errorCode); }
        }
    }

    public function testServerErrorLeavesUnknownOutcomeWithoutBlindRetry(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $http->responses = [$this->token(), $this->response(['error' => 'Unavailable'], 503)];
        try { $provider->create($intent, 'https://merchant.invalid/return', 'https://merchant.invalid/cancel'); self::fail('Accepted uncertain outcome'); }
        catch (WalletException $error) { self::assertSame('provider_outcome_unknown', $error->errorCode); }
        self::assertCount(2, $http->requests);
    }

    public function testProviderWholeUnitCurrenciesDoNotLoseFractionalValue(): void
    {
        [$provider, $http] = $this->fixture();
        $intent = new PaymentIntent(self::REFERENCE, 42, new Money(2500, Currency::of('HUF')));
        $order = $this->order();
        $order['purchase_units'][0]['amount'] = ['currency_code' => 'HUF', 'value' => '25'];
        $http->responses = [$this->token(), $this->response(['id' => self::ORDER], 201), $this->response($order)];
        $provider->create($intent, 'https://merchant.invalid/return', 'https://merchant.invalid/cancel');
        self::assertSame('25', json_decode($http->requests[1]['body'], true)['purchase_units'][0]['amount']['value']);
        [$provider, $http] = $this->fixture();
        $fractional = new PaymentIntent(self::REFERENCE, 42, new Money(2501, Currency::of('HUF')));
        try { $provider->create($fractional, 'https://merchant.invalid/return', 'https://merchant.invalid/cancel'); self::fail('Rounded provider amount'); }
        catch (WalletException $error) { self::assertSame('provider_precision_unsupported', $error->errorCode); }
        self::assertSame([], $http->requests);
    }

    public function testRefundPendingDoesNotClaimSettlement(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $reference = str_repeat('c', 32);
        $http->responses = [$this->token(), $this->response(['id' => '7AB00000000000001', 'status' => 'PENDING', 'invoice_id' => $reference, 'amount' => ['currency_code' => 'USD', 'value' => '25.00']], 201)];
        self::assertFalse($provider->refund(self::CAPTURE, $intent->amount, $reference)->settled());
        self::assertSame(substr(hash('sha256', 'paypal:refund:' . $reference), 0, 32), $http->requests[1]['headers']['PayPal-Request-Id']);
    }

    public function testSavedChargeRequiresEligibilityConsentAndProviderCustomerBinding(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $payment = new SavedPayment(42, 'CUSTOMER00001', 'TOKEN000001', str_repeat('c', 32));
        try { $provider->chargeSaved($intent, $payment); self::fail('Charged without eligibility'); }
        catch (WalletException $error) { self::assertSame('saved_payment_unavailable', $error->errorCode); }
        self::assertSame([], $http->requests);
        [$provider, $http, $intent] = $this->fixture(true, true);
        $http->responses = [$this->token(), $this->response(['id' => $payment->tokenId, 'customer' => ['id' => 'ANOTHER00001'], 'payment_source' => ['paypal' => []]])];
        try { $provider->chargeSaved($intent, $payment); self::fail('Charged another customer'); }
        catch (WalletException $error) { self::assertSame('saved_payment_mismatch', $error->errorCode); }
        self::assertCount(2, $http->requests);
    }

    public function testSavedChargeUsesMerchantUnscheduledPrepaidContract(): void
    {
        [$provider, $http, $intent] = $this->fixture(true, true);
        $payment = new SavedPayment(42, 'CUSTOMER00001', 'TOKEN000001', str_repeat('c', 32));
        $http->responses = [$this->token(), $this->response(['id' => $payment->tokenId, 'customer' => ['id' => $payment->customerId], 'payment_source' => ['paypal' => []]]), $this->response(['id' => self::ORDER], 201), $this->response($this->order('COMPLETED', 'COMPLETED'))];
        self::assertTrue($provider->chargeSaved($intent, $payment)->settled());
        $source = json_decode($http->requests[2]['body'], true)['payment_source']['paypal'];
        self::assertSame($payment->tokenId, $source['vault_id']);
        self::assertSame(['payment_initiator' => 'MERCHANT', 'usage' => 'SUBSEQUENT', 'usage_pattern' => 'UNSCHEDULED_PREPAID'], $source['stored_credential']);
    }

    public function testWebhookVerificationPreservesRawEventAndRejectsUntrustedCertificate(): void
    {
        [$provider, $http, $intent, $clock] = $this->fixture();
        $raw = '{ "id" : "WH-TEST0001", "event_type" : "PAYMENT.CAPTURE.COMPLETED" }';
        $headers = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-TEST', 'paypal-transmission-id' => 'TX-TEST', 'paypal-transmission-sig' => 'signature-test', 'paypal-transmission-time' => gmdate('c', $clock->now())];
        $http->responses = [$this->token(), $this->response(['verification_status' => 'SUCCESS'])];
        self::assertSame('WH-TEST0001', $provider->verifyWebhook($raw, $headers)['id']);
        self::assertStringContainsString(',"webhook_event":' . $raw . '}', $http->requests[1]['body']);
        foreach (['https://127.0.0.1/v1/notifications/certs/CERT-TEST', 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-TEST#fragment'] as $certificate) {
            $headers['paypal-cert-url'] = $certificate;
            try { $provider->verifyWebhook($raw, $headers); self::fail('Accepted untrusted certificate'); }
            catch (WalletException $error) { self::assertSame('invalid_webhook', $error->errorCode); }
        }
        self::assertCount(2, $http->requests);
    }

    public function testCredentialObjectsDoNotExposeSecretsThroughSerialization(): void
    {
        $objects = [new Configuration(true, 'sandbox', 'test_client_id_abc', 'test_secret_id_abc', self::MERCHANT, 'test_webhook_id_abc'), new SavedPayment(42, 'CUSTOMER00001', 'TOKEN000001', str_repeat('c', 32))];
        foreach ($objects as $object) {
            $json = json_encode($object, JSON_THROW_ON_ERROR);
            self::assertStringContainsString('[redacted]', $json);
            foreach (['test_secret_id_abc', 'test_client_id_abc', 'TOKEN000001', 'CUSTOMER00001'] as $secret) {
                self::assertStringNotContainsString($secret, $json);
            }
            try { serialize($object); self::fail('Serialized plaintext credentials'); }
            catch (LogicException $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }

    public function testForgedWebhookSignatureCannotReachFinancialConsumer(): void
    {
        [$provider, $http, $intent, $clock] = $this->fixture();
        $headers = ['paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-TEST', 'paypal-transmission-id' => 'TX-TEST', 'paypal-transmission-sig' => 'forged-signature', 'paypal-transmission-time' => gmdate('c', $clock->now())];
        $http->responses = [$this->token(), $this->response(['verification_status' => 'FAILURE'])];
        try { $provider->verifyWebhook('{"id":"WH-TEST0001"}', $headers); self::fail('Accepted signature'); }
        catch (WalletException $error) { self::assertSame('webhook_signature_invalid', $error->errorCode); }
    }

    public function testRefundInspectionRequiresOriginalCaptureAmountAndInvoice(): void
    {
        [$provider, $http, $intent] = $this->fixture();
        $id = '1JU08902781691411';
        $reference = str_repeat('b', 32);
        $resource = ['id' => $id, 'amount' => ['currency_code' => 'USD', 'value' => '25.00'], 'status' => 'COMPLETED', 'invoice_id' => $reference, 'links' => [['rel' => 'up', 'method' => 'GET', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/' . self::CAPTURE]]];
        $http->responses = [$this->token(), $this->response($resource)];
        self::assertTrue($provider->inspectRefund($id, self::CAPTURE, $intent->amount, $reference)->settled());
        $resource['links'][0]['href'] = 'https://api-m.sandbox.paypal.com/v2/payments/captures/OTHER00000000001';
        $http->responses = [$this->response($resource)];
        try { $provider->inspectRefund($id, self::CAPTURE, $intent->amount, $reference); self::fail('Accepted foreign refund'); }
        catch (WalletException $error) { self::assertSame('provider_response_mismatch', $error->errorCode); }
    }
}
