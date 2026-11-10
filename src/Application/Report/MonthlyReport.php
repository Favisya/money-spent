<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Domain\Money\Money;

final readonly class MonthlyReport
{
    /**
     * @param list<CategoryTotal>    $categories biggest first
     * @param list<ParticipantTotal> $participants
     */
    public function __construct(
        public MonthPeriod $period,
        public Money $total,
        public array $categories,
        public array $participants,
        public int $pendingCount,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->categories === [] && $this->pendingCount === 0;
    }
}
