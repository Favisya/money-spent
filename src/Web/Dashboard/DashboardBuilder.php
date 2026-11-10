<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Application\Analytics\AnalysisPeriod;
use App\Application\Analytics\AnalyticsService;
use App\Application\Balance\BalanceReport;
use App\Application\Balance\BalanceService;
use App\Application\Expense\ExpenseView;
use App\Application\Reference\CurrencyCatalog;
use App\Application\Report\MonthlyReport;
use App\Application\Report\MonthlyReportService;
use App\Application\Report\MonthPeriod;
use App\Domain\Money\Money;
use App\Infrastructure\Persistence\CategoryRepository;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\ParticipantRepository;
use App\Infrastructure\Persistence\SettlementRepository;

final readonly class DashboardBuilder
{
    private const int TREND_MONTHS = 12;

    public function __construct(
        private MonthlyReportService $reports,
        private BalanceService $balances,
        private ExpenseRepository $expenses,
        private SettlementRepository $settlements,
        private CategoryRepository $categories,
        private ParticipantRepository $participants,
        private CurrencyCatalog $currencies,
        private AnalyticsService $analytics,
    ) {
    }

    public function build(MonthPeriod $period, MonthPeriod $current, ?string $ownerKey = null): Dashboard
    {
        $report   = $this->reports->build($period);
        $balance  = $this->balances->current();
        $owners   = $this->analytics->analyze(new AnalysisPeriod($period, $period), null);
        $filter   = $ownerKey === null ? null : $owners->owner($ownerKey);
        $expenses = array_reverse($this->expenses->findViews($period->start(), $period->endExclusive()));
        $shown    = $filter === null ? $expenses : array_values(array_filter(
            $expenses,
            static fn (ExpenseView $expense): bool => $owners->ownerFor($expense->payerId, $expense->split)->key
                === $filter->key,
        ));

        return new Dashboard(
            $period,
            $current,
            $report,
            $balance,
            $this->debtLine($balance, $report),
            $shown,
            $this->settlements->findViews($period->start(), $period->endExclusive()),
            $this->categories->findActive(),
            $this->participants->all(),
            $this->trend($period),
            $owners,
            $filter,
            count($expenses),
        );
    }

    /**
     * @return list<TrendPoint>
     */
    public function trend(MonthPeriod $last): array
    {
        $first = $last;

        for ($i = 1; $i < self::TREND_MONTHS; ++$i) {
            $first = $first->previous();
        }

        $totals = $this->expenses->monthlyTotals($first->start(), $last->endExclusive());
        $max    = max([0, ...array_values($totals)]);
        $points = [];

        for ($period = $first; !$period->isAfter($last); $period = MonthPeriod::containing($period->endExclusive())) {
            $total    = $totals[$period->key()] ?? 0;
            $points[] = new TrendPoint(
                $period,
                Money::ofMinor($total, $this->currencies->base()),
                $max > 0 ? (int) round(100 * max(0, $total) / $max) : 0,
            );
        }

        return $points;
    }

    private function debtLine(BalanceReport $balance, MonthlyReport $report): ?DebtLine
    {
        $people = array_values($balance->participants);

        if (count($people) !== 2) {
            return null;
        }

        $debt = $balance->balance->debts[0] ?? null;

        if ($debt === null) {
            return new DebtLine($people[0], $people[1], null, null, Money::zero($balance->base), 0);
        }

        $scale = max($debt->amountBaseMinor, intdiv(max(0, $report->total->minor), 2));

        return new DebtLine(
            $people[0],
            $people[1],
            $balance->participants[$debt->debtorId],
            $balance->participants[$debt->creditorId],
            Money::ofMinor($debt->amountBaseMinor, $balance->base),
            max(4, (int) round(100 * $debt->amountBaseMinor / $scale)),
        );
    }
}
