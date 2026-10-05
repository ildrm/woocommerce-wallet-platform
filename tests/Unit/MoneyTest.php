<?php
declare(strict_types=1);

namespace WalletPlatform\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WalletPlatform\Domain\Shared\Currency;
use WalletPlatform\Domain\Shared\Money;
use WalletPlatform\Domain\Shared\WalletException;

final class MoneyTest extends TestCase
{
    public function testAllocationConservesLargeAmounts(): void
    {
        $money = new Money(Money::MAX_MINOR, Currency::of('USD'));
        $parts = $money->allocate([10000, 9999, 1, 0]);
        self::assertSame($money->minor, array_sum(array_map(static fn (Money $part): int => $part->minor, $parts)));
        self::assertSame(0, $parts[3]->minor);
    }

    public function testCurrenciesCannotBeAdded(): void
    {
        $this->expectException(WalletException::class);
        (new Money(100, Currency::of('USD')))->add(new Money(100, Currency::of('JPY')));
    }

    public function testDecimalRoundTrip(): void
    {
        foreach (['JPY' => '3', 'USD' => '0.01', 'KWD' => '-12.345', 'CLF' => '123.4567'] as $currency => $decimal) {
            self::assertSame($decimal, Money::fromDecimal($decimal, Currency::of($currency))->decimal());
        }
    }
}
