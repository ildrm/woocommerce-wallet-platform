<?php

declare(strict_types=1);

namespace WalletPlatform\Presentation;

use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;

final class Format
{
    public static function money(int $minor, string $code, int $exponent): string
    {
        return $code . ' ' . (new Money($minor, new Currency($code, $exponent)))->decimal();
    }

    public static function operation(string $operation): string
    {
        return match ($operation) {
            'credit' => __('Credit added', 'wallet-platform'),
            'debit' => __('Wallet adjustment', 'wallet-platform'),
            'reserve' => __('Funds reserved', 'wallet-platform'),
            'capture' => __('Wallet payment', 'wallet-platform'),
            'release' => __('Reservation released', 'wallet-platform'),
            'refund' => __('Payment refunded', 'wallet-platform'),
            'topup_complete' => __('Wallet funding received', 'wallet-platform'),
            'topup_reverse' => __('Wallet funding returned', 'wallet-platform'),
            'lot_lifecycle' => __('Credit availability or expiration', 'wallet-platform'),
            default => __('Wallet transaction', 'wallet-platform'),
        };
    }

    public static function aggregate(string $minor, string $code, int $exponent): string
    {
        if (!preg_match('/^[0-9]{1,65}$/D', $minor) || $exponent < 0 || $exponent > 4) {
            throw new \RuntimeException('Invalid financial report amount.');
        }
        $digits = str_pad(ltrim($minor, '0') ?: '0', $exponent + 1, '0', STR_PAD_LEFT);
        return $code . ' ' . ($exponent === 0 ? $digits : substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent));
    }

    public static function state(string $state): string
    {
        return match ($state) {
            'active' => __('Active', 'wallet-platform'), 'pending' => __('Pending', 'wallet-platform'),
            'frozen' => __('Frozen', 'wallet-platform'), 'suspended' => __('Suspended', 'wallet-platform'),
            'closed' => __('Closed', 'wallet-platform'), default => __('Unavailable', 'wallet-platform'),
        };
    }

    public static function fundingState(string $state): string
    {
        return match ($state) {
            'created' => __('Request created', 'wallet-platform'),
            'pending_payment' => __('Awaiting payment', 'wallet-platform'),
            'completed' => __('Funding received', 'wallet-platform'),
            'refund_review' => __('Refund needs review', 'wallet-platform'),
            'refunded' => __('Funding returned', 'wallet-platform'),
            'partially_refunded' => __('Part of funding returned', 'wallet-platform'),
            default => __('Unavailable', 'wallet-platform'),
        };
    }

    public static function paymentState(string $state): string
    {
        return match ($state) {
            'prepared' => __('Ready for payment', 'wallet-platform'),
            'creating', 'capturing', 'requesting' => __('Payment processing', 'wallet-platform'),
            'awaiting_approval' => __('Awaiting PayPal approval', 'wallet-platform'),
            'capture_pending', 'pending' => __('Awaiting PayPal confirmation', 'wallet-platform'),
            'external_settled' => __('PayPal confirmed; wallet confirmation pending', 'wallet-platform'),
            'completed' => __('Completed', 'wallet-platform'),
            'review' => __('Needs review', 'wallet-platform'),
            'cancellation_pending' => __('Cancellation pending', 'wallet-platform'),
            'cancelled' => __('Cancelled', 'wallet-platform'),
            'compensating' => __('Returning payment', 'wallet-platform'),
            'compensated' => __('Payment returned', 'wallet-platform'),
            default => __('Awaiting review', 'wallet-platform'),
        };
    }
}
