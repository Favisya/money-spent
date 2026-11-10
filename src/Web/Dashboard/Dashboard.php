<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Application\Analytics\Owner;
use App\Application\Analytics\SpendingAnalysis;
use App\Application\Balance\BalanceReport;
use App\Application\Expense\ExpenseView;
use App\Application\Participant\Participant;
use App\Application\Reference\Category;
use App\Application\Report\MonthlyReport;
use App\Application\Report\MonthPeriod;
use App\Application\Settlement\SettlementView;

final readonly class Dashboard
{
    /**
     * @param list<ExpenseView>    $expenses newest first
     * @param list<SettlementView> $settlements
     * @param list<Category>       $categories
     * @param list<Participant>    $participants
     * @param list<TrendPoint>     $trend oldest first
     * @param int                  $totalCount expenses in the month before the owner filter
     */
    public function __construct(
        public MonthPeriod $period,
        public MonthPeriod $current,
        public MonthlyReport $report,
        public BalanceReport $balance,
        public ?DebtLine $debtLine,
        public array $expenses,
        public array $settlements,
        public array $categories,
        public array $participants,
        public array $trend,
        public SpendingAnalysis $owners,
        public ?Owner $filter,
        public int $totalCount,
    ) {
    }

    public function ownerOf(ExpenseView $expense): Owner
    {
        return $this->owners->ownerFor($expense->payerId, $expense->split);
    }

    public function categoryShare(int $amountMinor): int
    {
        $max = $this->report->categories[0]->amount->minor ?? 0;

        return $max > 0 ? (int) round(100 * max(0, $amountMinor) / $max) : 0;
    }
}
