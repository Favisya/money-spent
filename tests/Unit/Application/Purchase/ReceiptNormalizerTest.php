<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Purchase;

use App\Application\Purchase\MoneyText;
use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptContent;
use App\Application\Purchase\ReceiptNormalizer;
use App\Application\Purchase\RecognizedReceipt;
use App\Application\UserError;
use App\Domain\Money\Currency;
use App\Domain\Purchase\Unit;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptNormalizerTest extends TestCase
{
    private ReadingContext $context;

    protected function setUp(): void
    {
        $amd = new Currency('AMD', 0);
        $eur = new Currency('EUR', 2);

        $this->context = new ReadingContext(
            new DateTimeImmutable('2026-11-10'),
            $amd,
            ['AMD' => $amd, 'EUR' => $eur],
            'ru',
        );
    }

    public function testCleanAnswer(): void
    {
        $content = (new ReceiptNormalizer())->normalize(RecognizedReceipt::fromArray([
            'store'    => '  SAS Supermarket ',
            'date'     => '2026-11-09',
            'currency' => 'eur',
            'total'    => '12.40',
            'items'    => [
                ['name' => 'Сыр', 'quantity' => '0,346', 'unit' => 'kg', 'unit_price' => '20', 'amount' => ''],
                ['name' => 'Скидка', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '', 'amount' => '-0.50'],
            ],
        ]), $this->context);

        self::assertSame('SAS Supermarket', $content->store);
        self::assertSame('2026-11-09', $content->purchasedAt->format('Y-m-d'));
        self::assertSame('EUR', $content->basket->currency->code);
        self::assertSame(1240, $content->printedTotal?->minor);
        self::assertSame(
            [692, -50],
            array_map(static fn ($line): int => $line->amount->minor, $content->basket->lines),
        );
        self::assertSame(Unit::Kilogram, $content->basket->lines[0]->quantity->unit);
        self::assertSame(642, $content->basket->total()->minor);
    }

    public function testDeliveryIsKeptOnlyWhenItCostsSomething(): void
    {
        $normalize = fn (string $delivery): ReceiptContent => (new ReceiptNormalizer())->normalize(
            RecognizedReceipt::fromArray([
                'currency' => 'AMD',
                'total'    => '4650',
                'delivery' => $delivery,
                'items'    => [
                    ['name' => 'Молоко', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '', 'amount' => '4150'],
                ],
            ]),
            $this->context,
        );

        $paid = $normalize('500');
        self::assertSame(500, $paid->delivery?->minor);
        self::assertSame(4650, $paid->total()->minor);
        self::assertTrue($paid->differenceFromPrinted()?->isZero());

        self::assertNull($normalize('')->delivery);
        self::assertNull($normalize('0')->delivery);
        self::assertNull($normalize('-200')->delivery);
        self::assertSame(500, $normalize('')->differenceFromPrinted()?->minor);
    }

    public function testSloppyAnswerFallsBackToSafeDefaults(): void
    {
        $content = (new ReceiptNormalizer())->normalize(RecognizedReceipt::fromArray([
            'store'    => '',
            'date'     => '2027-01-01',
            'currency' => 'XYZ',
            'total'    => 'много',
            'items'    => [
                ['name' => '', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '1', 'amount' => '1'],
                ['name' => 'Пакет', 'quantity' => '1.5', 'unit' => 'pcs', 'unit_price' => '', 'amount' => '20'],
                ['name' => 'Непонятно', 'quantity' => 'x', 'unit' => 'бочка', 'unit_price' => '', 'amount' => ''],
                'мусор',
            ],
        ]), $this->context);

        self::assertNull($content->store);
        self::assertSame('2026-11-10', $content->purchasedAt->format('Y-m-d'));
        self::assertSame('AMD', $content->basket->currency->code);
        self::assertNull($content->printedTotal);
        self::assertCount(2, $content->basket->lines);
        self::assertSame('1', $content->basket->lines[0]->quantity->format());
        self::assertSame(0, $content->basket->lines[1]->amount->minor);
    }

    public function testOldDateIsReplacedByToday(): void
    {
        $content = (new ReceiptNormalizer())->normalize(
            new RecognizedReceipt('', '2020-02-30', '', '', []),
            $this->context,
        );

        self::assertSame('2026-11-10', $content->purchasedAt->format('Y-m-d'));
    }

    public function testAnswerWithoutItemsIsRejected(): void
    {
        $this->expectException(UserError::class);

        RecognizedReceipt::fromArray(['store' => 'x']);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function moneyProvider(): iterable
    {
        yield 'spaces' => ['1 200', 1200];
        yield 'thousand comma' => ['1,200.00', 1200];
        yield 'nbsp' => ["17\u{00A0}000", 17000];
        yield 'negative' => ['-200', -200];
        yield 'too precise' => ['12.5', null];
        yield 'empty' => ['', null];
        yield 'words' => ['бесплатно', null];
    }

    #[DataProvider('moneyProvider')]
    public function testMoneyText(string $text, ?int $minor): void
    {
        self::assertSame($minor, MoneyText::parse($text, new Currency('AMD', 0))?->minor);
    }
}
