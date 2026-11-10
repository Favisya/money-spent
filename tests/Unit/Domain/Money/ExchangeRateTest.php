<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Money;

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\ExchangeRate;
use App\Domain\Money\InvalidAmountException;
use App\Domain\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExchangeRateTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, string, int, int}>
     */
    public static function conversionProvider(): iterable
    {
        yield 'eur to amd rounds down' => [1850, 2, '403.36', 0, 7462];
        yield 'rub to amd rounds up' => [250000, 2, '4.4567', 0, 11142];
        yield 'exact half rounds away' => [50, 2, '1', 0, 1];
        yield 'negative half rounds away' => [-50, 2, '1', 0, -1];
        yield 'negative refund' => [-250000, 2, '4.4567', 0, -11142];
        yield 'to base with cents' => [17000, 0, '0.0025', 2, 4250];
        yield 'same exponent' => [100, 2, '1.5', 2, 150];
    }

    #[DataProvider('conversionProvider')]
    public function testConvert(int $minor, int $fromExponent, string $rate, int $baseExponent, int $expected): void
    {
        $from         = new Currency('XXX', $fromExponent);
        $base         = new Currency('BAS', $baseExponent);
        $exchangeRate = new ExchangeRate($from, $base, $rate, new DateTimeImmutable('2026-11-10'));

        $converted = $exchangeRate->convert(Money::ofMinor($minor, $from));

        self::assertSame($expected, $converted->minor);
        self::assertTrue($converted->currency->equals($base));
    }

    public function testRateIsNormalizedToScale(): void
    {
        $rate = new ExchangeRate(new Currency('EUR', 2), new Currency('AMD', 0), '403.36', new DateTimeImmutable());

        self::assertSame('403.36000000', $rate->rate);
    }

    public function testIdentity(): void
    {
        $amd  = new Currency('AMD', 0);
        $rate = ExchangeRate::identity($amd, new DateTimeImmutable());

        self::assertSame(17000, $rate->convert(Money::ofMinor(17000, $amd))->minor);
    }

    /**
     * @return iterable<string, array{int, string, int, int}>
     */
    public static function fromBaseProvider(): iterable
    {
        yield 'amd to usd' => [10000, '387.5', 2, 2581];
        yield 'amd to rub rounds half up' => [9, '4.5', 2, 200];
        yield 'negative refund' => [-7750, '387.5', 2, -2000];
        yield 'zero' => [0, '400', 2, 0];
        yield 'to a zero-exponent currency' => [1000, '3', 0, 333];
    }

    #[DataProvider('fromBaseProvider')]
    public function testConvertFromBase(int $baseMinor, string $rate, int $exponent, int $expected): void
    {
        $amd       = new Currency('AMD', 0);
        $target    = new Currency('XXX', $exponent);
        $exchange  = new ExchangeRate($target, $amd, $rate, new DateTimeImmutable());
        $converted = $exchange->convertFromBase(Money::ofMinor($baseMinor, $amd));

        self::assertSame($expected, $converted->minor);
        self::assertSame('XXX', $converted->currency->code);
    }

    public function testConvertFromBaseRejectsForeignMoney(): void
    {
        $rate = new ExchangeRate(new Currency('EUR', 2), new Currency('AMD', 0), '400', new DateTimeImmutable());

        $this->expectException(CurrencyMismatchException::class);

        $rate->convertFromBase(Money::ofMinor(100, new Currency('EUR', 2)));
    }

    public function testConvertRejectsOtherCurrency(): void
    {
        $rate = new ExchangeRate(new Currency('EUR', 2), new Currency('AMD', 0), '400', new DateTimeImmutable());

        $this->expectException(CurrencyMismatchException::class);

        $rate->convert(Money::ofMinor(100, new Currency('RUB', 2)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRateProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'text' => ['abc'];
        yield 'comma' => ['4,5'];
    }

    #[DataProvider('invalidRateProvider')]
    public function testInvalidRate(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExchangeRate(new Currency('EUR', 2), new Currency('AMD', 0), $rate, new DateTimeImmutable());
    }

    public function testSelfRateMustBeOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExchangeRate(new Currency('AMD', 0), new Currency('AMD', 0), '2', new DateTimeImmutable());
    }

    public function testOverflow(): void
    {
        $eur  = new Currency('EUR', 0);
        $rate = new ExchangeRate($eur, new Currency('AMD', 0), '1000', new DateTimeImmutable());

        $this->expectException(InvalidAmountException::class);

        $rate->convert(Money::ofMinor(PHP_INT_MAX, $eur));
    }
}
