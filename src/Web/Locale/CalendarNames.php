<?php

declare(strict_types=1);

namespace App\Web\Locale;

use App\Application\Report\MonthPeriod;
use App\Bot\View\DateText;
use App\Bot\View\MonthNames;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Month and weekday names for the web pages, in the language of the request: "Ноябрь 2026" / "November 2026",
 * "ноя" / "Nov", "пн" / "Mon".
 */
final readonly class CalendarNames
{
    private const string MONTH_PATTERN   = 'LLLL';
    private const string WEEKDAY_PATTERN = 'ccc';
    private const string ZONE            = 'UTC';
    private const string A_MONDAY        = '2024-01-01 12:00';
    private const int SHORT_MONTH        = 3;
    private const int DAYS_IN_WEEK       = 7;

    public function __construct(
        private MonthNames $months,
        private DateText $dates,
    ) {
    }

    public function month(MonthPeriod $period): string
    {
        return $this->months->label($period);
    }

    /**
     * The first three letters of the standalone name: "ноя", "Nov".
     */
    public function shortMonth(MonthPeriod $period): string
    {
        $date = new DateTimeImmutable(sprintf('2000-%02d-15 12:00', $period->month), new DateTimeZone(self::ZONE));

        return mb_substr($this->dates->format($date, self::MONTH_PATTERN), 0, self::SHORT_MONTH);
    }

    /**
     * @return array<int, string> ISO day number (1 is Monday) => short name: "пн", "Mon"
     */
    public function weekdays(): array
    {
        $monday = new DateTimeImmutable(self::A_MONDAY, new DateTimeZone(self::ZONE));
        $names  = [];

        for ($day = 1; $day <= self::DAYS_IN_WEEK; ++$day) {
            $names[$day] = $this->dates->format($monday->modify(sprintf('+%d days', $day - 1)), self::WEEKDAY_PATTERN);
        }

        return $names;
    }
}
