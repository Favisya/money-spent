<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Money;
use App\Domain\Split\SplitCalculator;
use App\Infrastructure\Persistence\ExpenseRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\LedgerTable;
use App\Infrastructure\Persistence\ParticipantRepository;

final readonly class MonthlyReportService
{
    public function __construct(
        private ExpenseRepository $expenses,
        private ParticipantRepository $participants,
        private LedgerEntryRepository $entries,
        private SplitCalculator $splitCalculator,
        private CurrencyCatalog $currencies,
    ) {
    }

    public function build(MonthPeriod $period): MonthlyReport
    {
        $base         = $this->currencies->base();
        $from         = $period->start();
        $to           = $period->endExclusive();
        $categories   = $this->expenses->categoryTotals($from, $to, $base);
        $participants = $this->participants->all();
        $ids          = array_map(static fn ($participant): int => $participant->id, $participants);
        $paid         = array_fill_keys($ids, 0);
        $shares       = array_fill_keys($ids, 0);

        foreach ($this->expenses->ledger($from, $to) as $expense) {
            $paid[$expense->payerId] += $expense->amountBaseMinor;

            $split = $this->splitCalculator->shares(
                $expense->payerId,
                $expense->amountBaseMinor,
                $expense->split,
                $ids,
            );

            foreach ($split as $participantId => $share) {
                $shares[$participantId] += $share;
            }
        }

        $totals = [];

        foreach ($participants as $participant) {
            $totals[] = new ParticipantTotal(
                $participant,
                Money::ofMinor($paid[$participant->id], $base),
                Money::ofMinor($shares[$participant->id], $base),
            );
        }

        return new MonthlyReport(
            $period,
            Money::ofMinor(array_sum($paid), $base),
            $categories,
            $totals,
            $this->entries->countPending(LedgerTable::Expense, $from, $to),
        );
    }
}
