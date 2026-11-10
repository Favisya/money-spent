<?php

declare(strict_types=1);

namespace App\Bot\View;

use App\Application\Report\MonthPeriod;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Month and year in the current language: "Ноябрь 2026" / "November 2026".
 */
final readonly class MonthNames
{
    private const string PATTERN = 'LLLL y';

    public function __construct(
        private DateText $dates,
    ) {
    }

    /**
     * Capitalized, for titles.
     */
    public function label(MonthPeriod $period): string
    {
        $inline = $this->inline($period);

        return mb_strtoupper(mb_substr($inline, 0, 1)) . mb_substr($inline, 1);
    }

    /**
     * As written mid-sentence: lower case in Russian, capitalized in English.
     */
    public function inline(MonthPeriod $period): string
    {
        $first = new DateTimeImmutable(
            sprintf('%04d-%02d-01', $period->year, $period->month),
            new DateTimeZone('UTC'),
        );

        return $this->dates->format($first, self::PATTERN);
    }
}
