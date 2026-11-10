<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Analytics;

use App\Application\Analytics\AnalysisPeriod;
use App\Application\Analytics\SpendingAnalysis;
use App\Application\Analytics\SpendingAnalyzer;
use App\Application\Analytics\SpendingRow;
use App\Application\Participant\Participant;
use App\Application\Reference\Category;
use App\Application\Report\MonthPeriod;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SpendingAnalyzerTest extends TestCase
{
    private const int FOOD = 10;
    private const int TAXI = 20;

    private Currency $amd;

    protected function setUp(): void
    {
        $this->amd = new Currency('AMD', 0);
    }

    public function testSplitsSharedAndPersonalMoney(): void
    {
        $analysis = $this->analyze(null);

        self::assertSame(['shared' => 30000, 'p1' => 700, 'p2' => 5000], $analysis->ownerTotals);
        self::assertSame(35700, $analysis->total);
        self::assertSame(4, $analysis->count);
        self::assertSame(1, $analysis->pendingCount);
        self::assertSame([self::FOOD => 30700, self::TAXI => 5000], $analysis->categoryTotals);
        self::assertEquals(
            ['shared' => [self::FOOD => 30000], 'p1' => [self::FOOD => 700], 'p2' => [self::TAXI => 5000]],
            $analysis->ownerCategory,
        );
        self::assertEquals(
            [1 => ['shared' => 10000, 'p1' => 700, 'p2' => 5000], 2 => ['shared' => 20000]],
            $analysis->payerOwner,
        );
        self::assertEquals(
            ['2026-10' => ['shared' => 10000], '2026-11' => ['shared' => 20000, 'p1' => 700, 'p2' => 5000]],
            $analysis->monthOwner,
        );
        self::assertSame(['2026-10-05' => 10000, '2026-11-02' => 25000, '2026-11-09' => 700], $analysis->days);
        self::assertSame(35700, $analysis->weekdays[1]);
        self::assertSame(0, $analysis->weekdays[7]);
        self::assertSame(intdiv(35700, 41), $analysis->dailyAverage);
    }

    public function testFilterKeepsOwnerTotalsButNarrowsTheRest(): void
    {
        $analysis = $this->analyze('p2');

        self::assertSame('Борис', $analysis->filter?->label);
        self::assertSame(['shared' => 30000, 'p1' => 700, 'p2' => 5000], $analysis->ownerTotals);
        self::assertSame(5000, $analysis->total);
        self::assertSame([self::TAXI => 5000], $analysis->categoryTotals);
        self::assertSame(0, $analysis->pendingCount);
    }

    public function testTopListsAndOwnerLookup(): void
    {
        $analysis = $this->analyze(null);

        $ids = array_map(static fn (SpendingRow $row): int => $row->id, $analysis->topExpenses);
        self::assertSame([3, 1, 2, 4], $ids);
        self::assertSame('кафе кари', $analysis->topNotes[0]->note);
        self::assertSame(2, $analysis->topNotes[0]->count);
        self::assertSame('такси', $analysis->topNotes[1]->note);
        self::assertSame('Борис', $analysis->ownerOf($analysis->topExpenses[2])->label);
        self::assertTrue($analysis->ownerOf($analysis->topExpenses[0])->isShared());
    }

    public function testUnknownFilterMeansEverything(): void
    {
        self::assertNull($this->analyze('p99')->filter);
    }

    private function analyze(?string $filter): SpendingAnalysis
    {
        $rows = [
            $this->row(1, 1, '2026-10-05', self::FOOD, SplitType::Equal, 10000, 'Кафе  Кари'),
            $this->row(2, 1, '2026-11-02', self::TAXI, SplitType::OtherOnly, 5000, 'такси'),
            $this->row(3, 2, '2026-11-02', self::FOOD, SplitType::Equal, 20000, 'кафе кари'),
            $this->row(4, 1, '2026-11-09', self::FOOD, SplitType::PayerOnly, 700, null),
            $this->row(5, 1, '2026-11-09', self::FOOD, SplitType::Equal, null, null),
        ];

        return (new SpendingAnalyzer())->analyze(
            new AnalysisPeriod(MonthPeriod::of(2026, 10), MonthPeriod::of(2026, 11)),
            $rows,
            [new Participant(1, null, 'Анна'), new Participant(2, null, 'Борис')],
            [new Category(self::FOOD, 'food', 'Еда', []), new Category(self::TAXI, 'taxi', 'Такси', [])],
            $this->amd,
            $filter,
            new DateTimeImmutable('2026-11-10 15:00'),
        );
    }

    private function row(
        int $id,
        int $payer,
        string $date,
        int $category,
        SplitType $split,
        ?int $amount,
        ?string $note,
    ): SpendingRow {
        return new SpendingRow(
            $id,
            $payer,
            new DateTimeImmutable($date),
            $category,
            $split,
            Money::ofMinor($amount ?? 100, $this->amd),
            $amount,
            $note,
        );
    }
}
