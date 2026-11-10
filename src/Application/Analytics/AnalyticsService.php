<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Application\Clock\LocalCalendar;
use App\Application\Reference\CurrencyCatalog;
use App\Application\Report\MonthPeriod;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\ParticipantRepository;

final readonly class AnalyticsService
{
    public function __construct(
        private ExpenseRepository $expenses,
        private ParticipantRepository $participants,
        private CategoryRepository $categories,
        private CurrencyCatalog $currencies,
        private LocalCalendar $calendar,
        private SpendingAnalyzer $analyzer,
    ) {
    }

    public function analyze(AnalysisPeriod $period, ?string $ownerKey): SpendingAnalysis
    {
        return $this->analyzer->analyze(
            $period,
            $this->expenses->spendingRows($period->start(), $period->endExclusive()),
            $this->participants->all(),
            $this->categories->findAll(),
            $this->currencies->base(),
            $ownerKey,
            $this->calendar->today(),
        );
    }

    /**
     * From the first recorded expense to the current month; just the current month when nothing is recorded.
     */
    public function allTime(): AnalysisPeriod
    {
        $current = MonthPeriod::containing($this->calendar->today());
        $first   = $this->expenses->firstMonth();
        $from    = $first === null ? $current : MonthPeriod::fromArgument($first, $this->calendar->today());

        return new AnalysisPeriod($from->isAfter($current) ? $current : $from, $current);
    }
}
