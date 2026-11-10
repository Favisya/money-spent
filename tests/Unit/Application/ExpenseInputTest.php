<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Expense\ExpenseInput;
use App\Application\UserError;
use App\Domain\Money\Currency;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpenseInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dayProvider(): iterable
    {
        yield 'day and month keep the year' => ['8.11', '2026-11-08'];
        yield 'full date' => ['01.02.2025', '2025-02-01'];
        yield 'short year' => ['31/12/25', '2025-12-31'];
        yield 'spaces around' => [' 09-11 ', '2026-11-09'];
    }

    #[DataProvider('dayProvider')]
    public function testDay(string $text, string $expected): void
    {
        $current = new DateTimeImmutable('2026-11-05 00:00', new DateTimeZone('Asia/Yerevan'));
        $day     = ExpenseInput::day($text, $current);

        self::assertSame($expected, $day->format('Y-m-d'));
        self::assertSame('Asia/Yerevan', $day->getTimezone()->getName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badDayProvider(): iterable
    {
        yield 'no such day' => ['31.02'];
        yield 'text' => ['вчера'];
        yield 'too old' => ['01.01.1999'];
    }

    #[DataProvider('badDayProvider')]
    public function testBadDay(string $text): void
    {
        $this->expectException(UserError::class);

        ExpenseInput::day($text, new DateTimeImmutable('2026-11-05'));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function amountProvider(): iterable
    {
        yield 'spaces as thousands' => ["17\u{00A0}500", 0, 17500];
        yield 'comma' => ['18,5', 2, 1850];
        yield 'dot' => ['1 000.00', 2, 100000];
    }

    #[DataProvider('amountProvider')]
    public function testAmount(string $text, int $exponent, int $minor): void
    {
        self::assertSame($minor, ExpenseInput::amount($text, new Currency('XXX', $exponent))->minor);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function badAmountProvider(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'negative' => ['-5', 0];
        yield 'too precise' => ['10,5', 0];
        yield 'words' => ['сто', 2];
    }

    #[DataProvider('badAmountProvider')]
    public function testBadAmount(string $text, int $exponent): void
    {
        $this->expectException(UserError::class);

        ExpenseInput::amount($text, new Currency('XXX', $exponent));
    }
}
