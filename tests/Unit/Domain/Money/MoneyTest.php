<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Money;

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\InvalidAmountException;
use App\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function decimalProvider(): iterable
    {
        yield 'integer amd' => ['17000', 0, 17000];
        yield 'comma separator' => ['18,5', 2, 1850];
        yield 'dot separator' => ['18.05', 2, 1805];
        yield 'trailing zeros amd' => ['100.00', 0, 100];
        yield 'negative' => ['-2500', 2, -250000];
        yield 'explicit plus' => ['+3', 2, 300];
        yield 'leading zeros' => ['007', 0, 7];
        yield 'zero' => ['0', 2, 0];
    }

    #[DataProvider('decimalProvider')]
    public function testFromDecimal(string $amount, int $exponent, int $expectedMinor): void
    {
        $money = Money::fromDecimal($amount, new Currency('XXX', $exponent));

        self::assertSame($expectedMinor, $money->minor);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidDecimalProvider(): iterable
    {
        yield 'too precise for amd' => ['1.5', 0];
        yield 'too precise for eur' => ['1.555', 2];
        yield 'too large' => ['1234567890123456', 0];
        yield 'letters' => ['12abc', 0];
        yield 'empty' => ['', 0];
        yield 'double separator' => ['1.2.3', 2];
    }

    #[DataProvider('invalidDecimalProvider')]
    public function testFromDecimalRejectsInvalid(string $amount, int $exponent): void
    {
        $this->expectException(InvalidAmountException::class);

        Money::fromDecimal($amount, new Currency('XXX', $exponent));
    }

    public function testArithmetic(): void
    {
        $eur = new Currency('EUR', 2);
        $a   = Money::ofMinor(1050, $eur);
        $b   = Money::ofMinor(250, $eur);

        self::assertSame(1300, $a->add($b)->minor);
        self::assertSame(800, $a->subtract($b)->minor);
        self::assertSame(-1050, $a->negate()->minor);
        self::assertSame(1050, $a->negate()->abs()->minor);
        self::assertTrue($a->subtract($a)->isZero());
        self::assertTrue($b->subtract($a)->isNegative());
        self::assertTrue($a->isPositive());
        self::assertTrue($a->equals(Money::ofMinor(1050, $eur)));
        self::assertFalse($a->equals(Money::ofMinor(1050, new Currency('RUB', 2))));
        self::assertTrue(Money::zero($eur)->isZero());
    }

    public function testAddingDifferentCurrenciesIsForbidden(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::ofMinor(1, new Currency('EUR', 2))->add(Money::ofMinor(1, new Currency('AMD', 0)));
    }

    public function testSubtractingDifferentCurrenciesIsForbidden(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::ofMinor(1, new Currency('EUR', 2))->subtract(Money::ofMinor(1, new Currency('RUB', 2)));
    }

    public function testOverflowIsDetected(): void
    {
        $amd = new Currency('AMD', 0);

        $this->expectException(InvalidAmountException::class);

        Money::ofMinor(PHP_INT_MAX, $amd)->add(Money::ofMinor(1, $amd));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function toDecimalProvider(): iterable
    {
        yield 'amd' => [17000, 0, '17000'];
        yield 'eur' => [1850, 2, '18.50'];
        yield 'cents only' => [5, 2, '0.05'];
        yield 'negative eur' => [-250000, 2, '-2500.00'];
        yield 'negative low' => [-5, 2, '-0.05'];
    }

    #[DataProvider('toDecimalProvider')]
    public function testToDecimal(int $minor, int $exponent, string $expected): void
    {
        self::assertSame($expected, Money::ofMinor($minor, new Currency('XXX', $exponent))->toDecimal());
    }

    public function testCurrencyValidation(): void
    {
        self::assertTrue((new Currency('AMD', 0))->equals(new Currency('AMD', 0)));

        $this->expectException(InvalidArgumentException::class);

        new Currency('amd', 0);
    }

    public function testCurrencyExponentValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Currency('EUR', 9);
    }

    public function testSplitKeepsTheWholeAmount(): void
    {
        $amd = new Currency('AMD', 0);

        self::assertSame([501, 500], array_map(
            static fn (Money $part): int => $part->minor,
            Money::ofMinor(1001, $amd)->split(2),
        ));
        self::assertSame([-34, -33, -33], array_map(
            static fn (Money $part): int => $part->minor,
            Money::ofMinor(-100, $amd)->split(3),
        ));
    }
}
