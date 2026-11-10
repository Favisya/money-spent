<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Application\Report\MonthPeriod;
use App\Domain\Money\Money;

final readonly class TrendPoint
{
    public function __construct(
        public MonthPeriod $period,
        public Money $total,
        public int $percent,
    ) {
    }
}
