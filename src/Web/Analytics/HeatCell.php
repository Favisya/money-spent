<?php

declare(strict_types=1);

namespace App\Web\Analytics;

use DateTimeImmutable;

final readonly class HeatCell
{
    /**
     * @param int $level 0 (nothing) .. 4 (the busiest days)
     */
    public function __construct(
        public DateTimeImmutable $date,
        public int $totalMinor,
        public int $level,
        public bool $inPeriod,
    ) {
    }
}
