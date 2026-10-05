<?php

declare(strict_types=1);

namespace WalletPlatform\Application\Port;

use WalletPlatform\Domain\Payment\PaymentIntent;
use WalletPlatform\Domain\Payment\ProviderOrder;
use WalletPlatform\Domain\Payment\ProviderRefund;
use WalletPlatform\Domain\Payment\SavedPayment;
use WalletPlatform\Domain\Shared\Money;

interface PaymentProvider
{
    public function supports(Money $amount): bool;
    public function create(PaymentIntent $intent, string $returnUrl, string $cancelUrl): ProviderOrder;
    public function inspect(string $orderId, PaymentIntent $intent): ProviderOrder;
    public function capture(string $orderId, PaymentIntent $intent): ProviderOrder;
    public function refund(string $captureId, Money $amount, string $refundReference): ProviderRefund;
    public function inspectRefund(string $refundId, string $captureId, Money $amount, string $refundReference): ProviderRefund;
    public function chargeSaved(PaymentIntent $intent, SavedPayment $payment): ProviderOrder;
    public function verifyWebhook(string $rawBody, array $headers): array;
}
