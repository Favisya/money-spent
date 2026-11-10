<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Analytics;

use App\Application\Analytics\AnalysisPeriod;
use App\Application\Report\MonthPeriod;
use App\Application\UserError;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AnalysisPeriodTest extends TestCase
{
    public function testLastMonthsCrossesYears(): void
    {
        $period = AnalysisPeriod::lastMonths(MonthPeriod::of(2026, 2), 3);

        self::assertSame('2025-12', $period->from->key());
        self::assertSame(3, $period->monthCount());
        self::assertSame(['2025-12', '2026-01', '2026-02'], array_map(
            static fn (MonthPeriod $month): string => $month->key(),
            $period->months(),
        ));
    }

    public function testElapsedDaysStopAtToday(): void
    {
        $period = new AnalysisPeriod(MonthPeriod::of(2026, 11), MonthPeriod::of(2026, 11));

        self::assertSame(10, $period->elapsedDays(new DateTimeImmutable('2026-11-10 23:00')));
        self::assertSame(30, $period->elapsedDays(new DateTimeImmutable('2027-01-01')));
        self::assertSame(1, $period->elapsedDays(new DateTimeImmutable('2026-10-01')));
    }

    public function testRejectsReversedRange(): void
    {
        $this->expectException(UserError::class);

        new AnalysisPeriod(MonthPeriod::of(2026, 5), MonthPeriod::of(2026, 4));
    }

    public function testRejectsTooLongRange(): void
    {
        $this->expectException(UserError::class);

        new AnalysisPeriod(MonthPeriod::of(2010, 1), MonthPeriod::of(2026, 1));
    }
}
