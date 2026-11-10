<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Recurring;

use App\Domain\Recurring\MonthlyRecurrence;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthlyRecurrenceTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string, int, list<string>}>
     */
    public static function dueProvider(): iterable
    {
        yield 'today is the day' => [10, '2026-11-01', '2026-11-10', 12, ['2026-11-10']];
        yield 'not yet this month' => [15, '2026-11-01', '2026-11-10', 12, []];
        yield 'catch up missed months' => [10, '2026-08-01', '2026-11-10', 12, [
            '2026-08-10',
            '2026-09-10',
            '2026-10-10',
            '2026-11-10',
        ]];
        yield 'day 31 in short months' => [31, '2026-01-01', '2026-04-30', 12, [
            '2026-01-31',
            '2026-02-28',
            '2026-03-31',
            '2026-04-30',
        ]];
        yield 'leap february' => [30, '2028-02-01', '2028-02-29', 12, ['2028-02-29']];
        yield 'limit' => [1, '2025-01-01', '2026-11-10', 3, ['2025-01-01', '2025-02-01', '2025-03-01']];
        yield 'future start' => [1, '2026-12-01', '2026-11-10', 12, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('dueProvider')]
    public function testDueDates(int $day, string $next, string $today, int $limit, array $expected): void
    {
        $dates = (new MonthlyRecurrence($day))->dueDates(
            new DateTimeImmutable($next),
            new DateTimeImmutable($today . ' 18:00'),
            $limit,
        );

        self::assertSame(
            $expected,
            array_map(static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates),
        );
    }

    public function testRejectsImpossibleDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonthlyRecurrence(32);
    }
}
