<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Application\Reference\Category;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;

/**
 * Aggregates in base minor units. Owner totals ignore the owner filter, everything else respects it.
 */
final readonly class SpendingAnalysis
{
    /**
     * @param list<Owner>                     $owners        shared first, then participants
     * @param array<int, Category>            $categories    by id, in reference order
     * @param array<string, int>              $ownerTotals   owner key => total
     * @param array<int, int>                 $categoryTotals category id => total, biggest first
     * @param array<string, array<int, int>>  $ownerCategory owner key => category id => total
     * @param array<int, array<string, int>>  $payerOwner    payer id => owner key => total
     * @param array<string, array<string, int>> $monthOwner  "Y-m" => owner key => total
     * @param array<string, array<int, int>>  $monthCategory "Y-m" => category id => total
     * @param array<string, int>              $days          "Y-m-d" => total
     * @param array<int, int>                 $weekdays      1 (Mon) .. 7 (Sun) => total
     * @param list<SpendingRow>               $topExpenses   biggest first
     * @param list<NoteTotal>                 $topNotes      biggest first
     */
    public function __construct(
        public AnalysisPeriod $period,
        public Currency $base,
        public array $owners,
        public ?Owner $filter,
        public array $categories,
        public int $total,
        public int $count,
        public int $pendingCount,
        public int $dailyAverage,
        public array $ownerTotals,
        public array $categoryTotals,
        public array $ownerCategory,
        public array $payerOwner,
        public array $monthOwner,
        public array $monthCategory,
        public array $days,
        public array $weekdays,
        public array $topExpenses,
        public array $topNotes,
    ) {
    }

    public function money(int $minor): Money
    {
        return Money::ofMinor($minor, $this->base);
    }

    public function owner(string $key): ?Owner
    {
        foreach ($this->owners as $owner) {
            if ($owner->key === $key) {
                return $owner;
            }
        }

        return null;
    }

    public function ownerOf(SpendingRow $row): Owner
    {
        return $this->ownerFor($row->payerId, $row->split);
    }

    public function ownerFor(int $payerId, SplitType $split): Owner
    {
        $ids = array_values(array_filter(array_map(
            static fn (Owner $owner): ?int => $owner->participantId,
            $this->owners,
        ), static fn (?int $id): bool => $id !== null));

        return $this->owner(Owner::keyFor($split->ownerId($payerId, $ids))) ?? Owner::shared();
    }

    public function ownersTotal(): int
    {
        return array_sum($this->ownerTotals);
    }

    public function share(int $part, int $whole): float
    {
        return $whole > 0 ? round(100 * $part / $whole, 1) : 0.0;
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
