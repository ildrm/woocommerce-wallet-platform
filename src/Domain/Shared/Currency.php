<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Shared;

final class Currency
{
    public function __construct(public readonly string $code, public readonly int $exponent)
    {
        if (!preg_match('/^[A-Z]{3}$/D', $code) || $exponent < 0 || $exponent > 4) {
            throw new WalletException('invalid_currency', 'Invalid currency metadata.');
        }
    }

    public static function of(string $code): self
    {
        $zero = ['JPY', 'KRW', 'VND', 'CLP', 'XAF', 'XOF', 'XPF', 'BIF', 'DJF', 'GNF', 'KMF', 'PYG', 'RWF', 'UGX', 'VUV'];
        $three = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];
        $two = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'CNY', 'HKD', 'INR', 'IRR', 'AED', 'SAR', 'TRY', 'SEK', 'NOK', 'DKK', 'PLN', 'ZAR', 'BRL', 'MXN', 'SGD', 'THB', 'IDR', 'MYR', 'PHP', 'PKR', 'BDT', 'EGP', 'ILS', 'RON', 'CZK', 'HUF', 'ISK', 'RUB', 'UAH', 'TWD'];
        if (in_array($code, $zero, true)) {
            return new self($code, 0);
        }
        if (in_array($code, $three, true)) {
            return new self($code, 3);
        }
        if (in_array($code, $two, true)) {
            return new self($code, 2);
        }
        if (in_array($code, ['CLF', 'UYW'], true)) {
            return new self($code, 4);
        }
        throw new WalletException('unsupported_currency', 'Currency requires explicit registered metadata.');
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->exponent === $other->exponent;
    }
}
