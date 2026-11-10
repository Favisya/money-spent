<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Parsing;

use App\Domain\Money\Currency;
use App\Domain\Parsing\CategoryKeywords;
use App\Domain\Parsing\ExpenseParseException;
use App\Domain\Parsing\ExpenseParser;
use App\Domain\Parsing\ParseError;
use App\Domain\Parsing\ParserDictionary;
use App\Domain\Split\SplitType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpenseParserTest extends TestCase
{
    private const string TODAY = '2026-11-10 15:30:00';

    private ExpenseParser $parser;

    protected function setUp(): void
    {
        $amd = new Currency('AMD', 0);
        $rub = new Currency('RUB', 2);
        $eur = new Currency('EUR', 2);
        $usd = new Currency('USD', 2);

        $this->parser = new ExpenseParser(new ParserDictionary(
            $amd,
            [
                'amd'  => $amd,
                'драм' => $amd,
                'др'   => $amd,
                '֏'    => $amd,
                'rub'  => $rub,
                'руб'  => $rub,
                'р'    => $rub,
                '₽'    => $rub,
                'eur'  => $eur,
                'евро' => $eur,
                '€'    => $eur,
                'usd'  => $usd,
                'долл' => $usd,
                '$'    => $usd,
            ],
            [
                new CategoryKeywords('groceries', ['продукт', 'магаз', 'Ашан', 'супермаркет']),
                new CategoryKeywords('delivery', ['доставк', 'глово', 'wolt']),
                new CategoryKeywords('utilities', ['коммуналк', 'свет', 'интернет']),
                new CategoryKeywords('gas', ['бензин', 'заправк', 'газ']),
                new CategoryKeywords('subscriptions', ['подписк', 'чатгпт', 'chatgpt', 'netflix']),
                new CategoryKeywords('other', ['прочее', '']),
            ],
            [
                'лично' => SplitType::PayerOnly,
                'себе'  => SplitType::PayerOnly,
                'ей'    => SplitType::OtherOnly,
                'ему'   => SplitType::OtherOnly,
            ],
        ));
    }

    /**
     * @return iterable<string, array{string, int, string, string, list<string>, SplitType, ?string}>
     */
    public static function validProvider(): iterable
    {
        $d   = '2026-11-10';
        $eq  = SplitType::Equal;
        $own = SplitType::PayerOnly;
        $for = SplitType::OtherOnly;

        yield 'default currency and date' => ['17000 продукты', 17000, 'AMD', $d, ['groceries'], $eq, 'продукты'];
        yield 'comma decimal, eur, date' => [
            '18,5 eur чатгпт 10.11',
            1850,
            'EUR',
            $d,
            ['subscriptions'],
            $eq,
            'чатгпт',
        ];
        yield 'yesterday with note' => [
            '9700 доставка кари вчера',
            9700,
            'AMD',
            '2026-11-09',
            ['delivery'],
            $eq,
            'доставка кари',
        ];
        yield 'negative refund in rub' => ['-2500 руб от алисы', -250000, 'RUB', $d, [], $eq, 'от алисы'];
        yield 'symbol after amount' => ['18.5 €', 1850, 'EUR', $d, [], $eq, null];
        yield 'symbol before amount' => ['€18.5 netflix', 1850, 'EUR', $d, ['subscriptions'], $eq, 'netflix'];
        yield 'dollar sign before amount' => ['$12.99 icloud', 1299, 'USD', $d, [], $eq, 'icloud'];
        yield 'usd word' => ['40 usd 09.11', 4000, 'USD', '2026-11-09', [], $eq, null];
        yield 'quoted keyword' => ['1200 «бензин»', 1200, 'AMD', $d, ['gas'], $eq, 'бензин'];
        yield 'detached plus sign' => ['+ 5000 06.11.2026', 5000, 'AMD', '2026-11-06', [], $eq, null];
        yield 'detached unicode minus' => ['− 300 руб', -30000, 'RUB', $d, [], $eq, null];
        yield 'lone sign is a note' => ['700 + кофе', 700, 'AMD', $d, [], $eq, '+ кофе'];
        yield 'note line with full date' => [
            '+9700 драм 05.11.2026 доставка кари',
            9700,
            'AMD',
            '2026-11-05',
            ['delivery'],
            $eq,
            'доставка кари',
        ];
        yield 'dollar sign after amount' => ['7$ долл', 700, 'USD', $d, [], $eq, 'долл'];
        yield 'alias glued to amount' => ['2500р бензин', 250000, 'RUB', $d, ['gas'], $eq, 'бензин'];
        yield 'day before yesterday' => ['1500 драм позавчера', 1500, 'AMD', '2026-11-08', [], $eq, null];
        yield 'keyword prefix match' => ['3000 магазин у дома', 3000, 'AMD', $d, ['groceries'], $eq, 'магазин у дома'];
        yield 'case insensitive, dedupe' => [
            '5000 ПРОДУКТЫ Ашан',
            5000,
            'AMD',
            $d,
            ['groceries'],
            $eq,
            'ПРОДУКТЫ Ашан',
        ];
        yield 'ambiguous category' => [
            '12000 продукты доставка',
            12000,
            'AMD',
            $d,
            ['groceries', 'delivery'],
            $eq,
            'продукты доставка',
        ];
        yield 'personal expense' => ['700 кофе лично', 700, 'AMD', $d, [], $own, 'кофе'];
        yield 'paid for the other one' => ['3000 цветы ей', 3000, 'AMD', $d, [], $for, 'цветы'];
        yield 'full date' => ['100 eur 01.01.2026', 10000, 'EUR', '2026-01-01', [], $eq, null];
        yield 'date in future goes back' => ['100 31.12', 100, 'AMD', '2025-12-31', [], $eq, null];
        yield 'two digit year' => ['100 15.03.25', 100, 'AMD', '2025-03-15', [], $eq, null];
        yield 'amount not first token' => ['такси 2000', 2000, 'AMD', $d, [], $eq, 'такси'];
        yield 'punctuation is ignored' => ['1000, продукты!', 1000, 'AMD', $d, ['groceries'], $eq, 'продукты'];
        yield 'second currency is a note' => ['1000 eur rub', 100000, 'EUR', $d, [], $eq, 'rub'];
        yield 'today word, euro word' => ['50.5 евро сегодня', 5050, 'EUR', $d, [], $eq, null];
        yield 'explicit plus sign' => ['+300 прочее', 300, 'AMD', $d, ['other'], $eq, 'прочее'];
        yield 'trailing zeros in amd' => ['18.00 драм', 18, 'AMD', $d, [], $eq, null];
        yield 'extra whitespace' => ["  700\t\tкофе   лично ", 700, 'AMD', $d, [], $own, 'кофе'];
        yield 'short keyword exact only' => ['1000 газета', 1000, 'AMD', $d, [], $eq, 'газета'];
        yield 'yo is normalized' => ['1000 Прочеё', 1000, 'AMD', $d, ['other'], $eq, 'Прочеё'];
    }

    /**
     * @param list<string> $categories
     */
    #[DataProvider('validProvider')]
    public function testParsesValidInput(
        string $input,
        int $minor,
        string $currency,
        string $date,
        array $categories,
        SplitType $split,
        ?string $note,
    ): void {
        $parsed = $this->parser->parse($input, new DateTimeImmutable(self::TODAY));

        self::assertSame($minor, $parsed->amount->minor);
        self::assertSame($currency, $parsed->amount->currency->code);
        self::assertSame($date . ' 00:00:00', $parsed->spentAt->format('Y-m-d H:i:s'));
        self::assertSame($categories, $parsed->categoryCodes);
        self::assertSame($split, $parsed->split);
        self::assertSame($note, $parsed->note);
        self::assertSame(count($categories) > 1, $parsed->isCategoryAmbiguous());
    }

    public function testReportsWhetherTheDateWasGiven(): void
    {
        $today = new DateTimeImmutable(self::TODAY);

        self::assertFalse($this->parser->parse('100 продукты', $today)->hasExplicitDate);
        self::assertTrue($this->parser->parse('100 продукты 01.11', $today)->hasExplicitDate);
        self::assertTrue($this->parser->parse('100 вчера', $today)->hasExplicitDate);

        $moved = $this->parser->parse('100 продукты', $today)->withSpentAt(new DateTimeImmutable('2026-10-01'));
        self::assertSame('2026-10-01', $moved->spentAt->format('Y-m-d'));
        self::assertSame(['groceries'], $moved->categoryCodes);
        self::assertTrue($moved->hasExplicitDate);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function dateLineProvider(): iterable
    {
        yield 'day and month' => ['07.11', '2026-11-07'];
        yield 'with year' => [' 30.09.2025 ', '2025-09-30'];
        yield 'relative word' => ['Вчера', '2026-11-09'];
        yield 'expense line' => ['100 продукты', null];
        yield 'single word' => ['Ноябрь', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('dateLineProvider')]
    public function testParseDateLine(string $line, ?string $expected): void
    {
        $date = $this->parser->parseDateLine($line, new DateTimeImmutable(self::TODAY));

        self::assertSame($expected, $date?->format('Y-m-d'));
    }

    public function testParseDateLineRejectsImpossibleDate(): void
    {
        $this->expectException(ExpenseParseException::class);

        $this->parser->parseDateLine('31.02', new DateTimeImmutable(self::TODAY));
    }

    /**
     * @return iterable<string, array{string, ParseError}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty' => ['', ParseError::Empty];
        yield 'whitespace only' => ["  \n\t ", ParseError::Empty];
        yield 'greeting' => ['привет', ParseError::NoAmount];
        yield 'words only' => ['продукты много', ParseError::NoAmount];
        yield 'emoji' => ['🙂🙂🙂', ParseError::NoAmount];
        yield 'unknown unit glued' => ['12шт яблок', ParseError::NoAmount];
        yield 'scientific notation' => ['1e5 продукты', ParseError::NoAmount];
        yield 'zero' => ['0 продукты', ParseError::ZeroAmount];
        yield 'negative zero' => ['-0,00 eur', ParseError::ZeroAmount];
        yield 'fraction for amd' => ['1.5 продукты', ParseError::TooPrecise];
        yield 'three decimals for eur' => ['18,555 eur', ParseError::TooPrecise];
        yield 'too large' => ['9999999999999999 драм', ParseError::AmountTooLarge];
        yield 'impossible date' => ['100 32.13', ParseError::InvalidDate];
        yield 'not a leap year' => ['100 29.02.2025', ParseError::InvalidDate];
        yield 'explicit future year' => ['100 01.01.2027', ParseError::FutureDate];
        yield 'too long' => [str_repeat('а ', 300), ParseError::TooLong];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidInput(string $input, ParseError $expected): void
    {
        try {
            $this->parser->parse($input, new DateTimeImmutable(self::TODAY));
        } catch (ExpenseParseException $exception) {
            self::assertSame($expected, $exception->error);

            return;
        }

        self::fail(sprintf('Expected %s for "%s".', $expected->value, $input));
    }

    public function testTooPreciseCarriesContext(): void
    {
        try {
            $this->parser->parse('18,555 eur', new DateTimeImmutable(self::TODAY));
        } catch (ExpenseParseException $exception) {
            self::assertSame(['amount' => '18,555', 'currency' => 'EUR', 'digits' => '2'], $exception->context);

            return;
        }

        self::fail('Exception expected.');
    }

    public function testLeapDayWithoutYearFallsBackToPreviousLeapYearOnlyWhenValid(): void
    {
        try {
            $this->parser->parse('100 29.02', new DateTimeImmutable('2028-01-10'));
        } catch (ExpenseParseException $exception) {
            self::assertSame(ParseError::FutureDate, $exception->error);

            return;
        }

        self::fail('Exception expected.');
    }

    public function testNoteIsTruncated(): void
    {
        $parsed = $this->parser->parse('100 ' . str_repeat('ж', 300), new DateTimeImmutable(self::TODAY));

        self::assertSame(255, mb_strlen((string) $parsed->note));
    }
}
