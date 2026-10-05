<?php

declare(strict_types=1);

namespace WalletPlatform\Domain\Shared;

final class Money implements \JsonSerializable
{
    public const MAX_MINOR = 9000000000000000;

    public function __construct(public readonly int $minor, public readonly Currency $currency)
    {
        if (PHP_INT_SIZE < 8 || $minor < -self::MAX_MINOR || $minor > self::MAX_MINOR) {
            throw new WalletException('money_overflow', 'Money exceeds supported integer range.');
        }
    }

    public static function fromDecimal(string $value, Currency $currency): self
    {
        if (strlen($value) > 24 || !preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $match)) {
            throw new WalletException('invalid_amount', 'Use a canonical decimal amount.');
        }
        $fraction = $match[3] ?? '';
        if (strlen($fraction) > $currency->exponent) {
            throw new WalletException('amount_precision', 'Amount has excess currency precision.');
        }
        $digits = ltrim($match[2] . str_pad($fraction, $currency->exponent, '0'), '0');
        $maximum = (string) self::MAX_MINOR;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new WalletException('money_overflow', 'Money exceeds supported integer range.');
        }
        return new self(($match[1] === '-' ? -1 : 1) * (int) $digits, $currency);
    }

    public function decimal(): string
    {
        $digits = (string) abs($this->minor);
        $sign = $this->minor < 0 ? '-' : '';
        if ($this->currency->exponent === 0) {
            return $sign . $digits;
        }
        $digits = str_pad($digits, $this->currency->exponent + 1, '0', STR_PAD_LEFT);
        return $sign . substr($digits, 0, -$this->currency->exponent) . '.' . substr($digits, -$this->currency->exponent);
    }

    public function add(self $other): self
    {
        $this->sameCurrency($other);
        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->sameCurrency($other);
        return new self($this->minor - $other->minor, $this->currency);
    }

    public function compare(self $other): int
    {
        $this->sameCurrency($other);
        return $this->minor <=> $other->minor;
    }

    /** Half-up percentage rounding; decomposition avoids multiplying large money values. */
    public function basisPoints(int $rate): self
    {
        if ($rate < 0 || $rate > 10000) {
            throw new WalletException('invalid_rate', 'Rate must be between zero and 10000 basis points.');
        }
        $absolute = abs($this->minor);
        $minor = intdiv($absolute, 10000) * $rate + intdiv(($absolute % 10000) * $rate + 5000, 10000);
        return new self($this->minor < 0 ? -$minor : $minor, $this->currency);
    }

    /** @param list<mixed> $weights @return list<self> */
    public function allocate(array $weights): array
    {
        if ($weights === [] || count($weights) > 100 || $this->minor < 0) {
            throw new WalletException('invalid_allocation', 'Allocation needs non-negative money and weights.');
        }
        $total = 0;
        foreach ($weights as $weight) {
            if (!is_int($weight) || $weight < 0 || $weight > 10000) {
                throw new WalletException('invalid_allocation', 'Invalid allocation weight.');
            }
            $total += $weight;
        }
        if ($total === 0) {
            throw new WalletException('invalid_allocation', 'Allocation weight sum must be positive.');
        }
        $parts = [];
        foreach ($weights as $weight) {
            $parts[] = intdiv($this->minor, $total) * $weight + intdiv(($this->minor % $total) * $weight, $total);
        }
        $remainder = $this->minor - array_sum($parts);
        foreach ($parts as $index => $_) {
            if ($remainder > 0 && $weights[$index] > 0) {
                ++$parts[$index];
                --$remainder;
            }
        }
        return array_map(fn (int $part): self => new self($part, $this->currency), $parts);
    }

    public function positive(): void
    {
        if ($this->minor <= 0) {
            throw new WalletException('invalid_amount', 'Amount must be positive.');
        }
    }

    /** Floor an exact non-negative ratio without floating point or a large product. */
    public function prorate(int $weight, int $total): self
    {
        if ($this->minor < 0 || $weight < 0 || $total <= 0 || $weight > $total || $total > self::MAX_MINOR) {
            throw new WalletException('invalid_allocation', 'Invalid proportional allocation.');
        }
        $bits = [];
        for ($value = $this->minor; $value > 0; $value = intdiv($value, 2)) {
            $bits[] = $value % 2;
        }
        $quotient = 0;
        $remainder = 0;
        foreach (array_reverse($bits) as $bit) {
            $step = $remainder * 2 + $bit * $weight;
            $quotient = $quotient * 2 + intdiv($step, $total);
            $remainder = $step % $total;
        }
        return new self($quotient, $this->currency);
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->decimal(), 'minor' => (string) $this->minor, 'currency' => $this->currency->code];
    }

    private function sameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw new WalletException('currency_mismatch', 'Explicit currency conversion is required.');
        }
    }
}
