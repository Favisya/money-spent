<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Purchase;

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Purchase\Basket;
use App\Domain\Purchase\Quantity;
use App\Domain\Purchase\ReceiptLine;
use App\Domain\Purchase\Unit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptLineTest extends TestCase
{
    private Currency $amd;

    protected function setUp(): void
    {
        $this->amd = new Currency('AMD', 0);
    }

    /**
     * @return iterable<string, array{string, ?Unit}>
     */
    public static function unitProvider(): iterable
    {
        yield 'russian kg' => ['кг', Unit::Kilogram];
        yield 'english with dot' => ['Kg.', Unit::Kilogram];
        yield 'pieces short' => ['шт', Unit::Piece];
        yield 'pieces armenian' => ['հատ', Unit::Piece];
        yield 'grams' => ['гр', Unit::Gram];
        yield 'liters' => ['л', Unit::Liter];
        yield 'milliliters' => [' ML ', Unit::Milliliter];
        yield 'unknown' => ['пачка', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('unitProvider')]
    public function testUnitFromText(string $text, ?Unit $expected): void
    {
        self::assertSame($expected, Unit::fromText($text));
    }

    public function testWeighedUnits(): void
    {
        self::assertFalse(Unit::Piece->isWeighed());
        self::assertTrue(Unit::Kilogram->isWeighed());
    }

    /**
     * @return iterable<string, array{string, Unit, string}>
     */
    public static function quantityProvider(): iterable
    {
        yield 'comma decimal' => ['1,5', Unit::Kilogram, '1.5'];
        yield 'whole pieces' => ['2', Unit::Piece, '2'];
        yield 'trailing zeros' => ['0.250', Unit::Kilogram, '0.25'];
        yield 'pieces with .0' => ['3.0', Unit::Piece, '3'];
        yield 'rounded to 3' => ['1.23456', Unit::Liter, '1.234'];
    }

    #[DataProvider('quantityProvider')]
    public function testQuantity(string $text, Unit $unit, string $formatted): void
    {
        $quantity = Quantity::of($text, $unit);

        self::assertSame($formatted, $quantity->format());
        self::assertSame($unit, $quantity->unit);
    }

    /**
     * @return iterable<string, array{string, Unit}>
     */
    public static function invalidQuantityProvider(): iterable
    {
        yield 'zero' => ['0', Unit::Kilogram];
        yield 'negative' => ['-1', Unit::Piece];
        yield 'text' => ['много', Unit::Piece];
        yield 'fraction pieces' => ['1.5', Unit::Piece];
        yield 'too long' => ['12345678901', Unit::Gram];
    }

    #[DataProvider('invalidQuantityProvider')]
    public function testInvalidQuantity(string $text, Unit $unit): void
    {
        $this->expectException(InvalidArgumentException::class);

        Quantity::of($text, $unit);
    }

    public function testAmountIsPriceTimesQuantity(): void
    {
        $line = ReceiptLine::of(
            '  Яблоки   голден ',
            Quantity::of('1.346', Unit::Kilogram),
            Money::ofMinor(890, $this->amd),
            null,
        );

        self::assertSame('Яблоки голден', $line->name);
        self::assertSame(1198, $line->amount->minor);
    }

    public function testPrintedAmountWins(): void
    {
        $line = ReceiptLine::of(
            'Молоко',
            Quantity::of('2', Unit::Piece),
            Money::ofMinor(600, $this->amd),
            Money::ofMinor(1100, $this->amd),
        );

        self::assertSame(1100, $line->amount->minor);
    }

    public function testDiscountLineWithoutPrice(): void
    {
        $line = ReceiptLine::of('Скидка', Quantity::one(), null, Money::ofMinor(-200, $this->amd));

        self::assertSame(-200, $line->amount->minor);
        self::assertNull($line->unitPrice);
    }

    public function testNameIsTruncated(): void
    {
        $line = ReceiptLine::of(str_repeat('я', 300), Quantity::one(), null, Money::ofMinor(1, $this->amd));

        self::assertSame(ReceiptLine::NAME_LENGTH, mb_strlen($line->name));
    }

    public function testLineNeedsPriceOrAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReceiptLine::of('Хлеб', Quantity::one(), null, null);
    }

    public function testLineNeedsName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReceiptLine::of("  \t", Quantity::one(), null, Money::ofMinor(100, $this->amd));
    }

    public function testLineCurrenciesMustMatch(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        ReceiptLine::of(
            'Сыр',
            Quantity::one(),
            Money::ofMinor(100, new Currency('EUR', 2)),
            Money::ofMinor(100, $this->amd),
        );
    }

    public function testBasketTotal(): void
    {
        $basket = new Basket($this->amd, [
            ReceiptLine::of('Хлеб', Quantity::one(), Money::ofMinor(450, $this->amd), null),
            ReceiptLine::of('Сыр', Quantity::of('0.3', Unit::Kilogram), Money::ofMinor(6000, $this->amd), null),
            ReceiptLine::of('Скидка', Quantity::one(), null, Money::ofMinor(-50, $this->amd)),
        ]);

        self::assertSame(2200, $basket->total()->minor);
        self::assertFalse($basket->isEmpty());
        self::assertTrue((new Basket($this->amd, []))->total()->isZero());
    }

    public function testBasketRejectsForeignLines(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $euro = ReceiptLine::of('Сыр', Quantity::one(), null, Money::ofMinor(1, new Currency('EUR', 2)));

        new Basket($this->amd, [$euro]);
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function multiplyProvider(): iterable
    {
        yield 'half rounds away' => [5, '0.5', 3];
        yield 'negative half' => [-5, '0.5', -3];
        yield 'weight' => [89000, '1.346', 119794];
        yield 'whole' => [450, '2', 900];
    }

    #[DataProvider('multiplyProvider')]
    public function testMoneyMultiply(int $minor, string $factor, int $expected): void
    {
        self::assertSame($expected, Money::ofMinor($minor, $this->amd)->multiply($factor)->minor);
    }

    public function testMoneyMultiplyRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(1, $this->amd)->multiply('1e3');
    }
}
