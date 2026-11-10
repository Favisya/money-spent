<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Application\Participant\Participant;
use App\Application\Reference\Category;
use App\Domain\Money\Currency;
use DateTimeImmutable;

/**
 * Pure aggregation of expense rows for the analytics page; no I/O.
 */
final readonly class SpendingAnalyzer
{
    private const int TOP_EXPENSES = 10;
    private const int TOP_NOTES    = 10;

    /**
     * @param list<SpendingRow> $rows
     * @param list<Participant> $participants
     * @param list<Category>    $categories
     */
    public function analyze(
        AnalysisPeriod $period,
        array $rows,
        array $participants,
        array $categories,
        Currency $base,
        ?string $filterKey,
        DateTimeImmutable $today,
    ): SpendingAnalysis {
        $owners = [Owner::shared()];

        foreach ($participants as $index => $participant) {
            $owners[] = Owner::personal($participant, $index + 1);
        }

        $ids    = array_map(static fn (Participant $participant): int => $participant->id, $participants);
        $filter = null;

        foreach ($owners as $owner) {
            $filter = $owner->key === $filterKey ? $owner : $filter;
        }

        $ownerTotals = array_fill_keys(array_map(static fn (Owner $owner): string => $owner->key, $owners), 0);
        $pending     = 0;
        $known       = [];

        foreach ($rows as $row) {
            $ownerKey = Owner::keyFor($row->split->ownerId($row->payerId, $ids));

            if ($filter !== null && $filter->key !== $ownerKey) {
                if ($row->amountBaseMinor !== null) {
                    $ownerTotals[$ownerKey] = ($ownerTotals[$ownerKey] ?? 0) + $row->amountBaseMinor;
                }

                continue;
            }

            if ($row->amountBaseMinor === null) {
                ++$pending;

                continue;
            }

            $ownerTotals[$ownerKey] = ($ownerTotals[$ownerKey] ?? 0) + $row->amountBaseMinor;
            $known[]                = [$row, $ownerKey, $row->amountBaseMinor];
        }

        return $this->aggregate(
            $period,
            $base,
            $owners,
            $filter,
            $this->indexCategories($categories),
            $ownerTotals,
            $known,
            $pending,
            $today,
        );
    }

    /**
     * @param list<Owner>                              $owners
     * @param array<int, Category>                     $categories
     * @param array<string, int>                       $ownerTotals
     * @param list<array{SpendingRow, string, int}>    $known
     */
    private function aggregate(
        AnalysisPeriod $period,
        Currency $base,
        array $owners,
        ?Owner $filter,
        array $categories,
        array $ownerTotals,
        array $known,
        int $pending,
        DateTimeImmutable $today,
    ): SpendingAnalysis {
        $total         = 0;
        $categoryTotal = [];
        $ownerCategory = [];
        $payerOwner    = [];
        $monthOwner    = array_fill_keys(array_map(static fn ($month): string => $month->key(), $period->months()), []);
        $monthCategory = $monthOwner;
        $days          = [];
        $weekdays      = array_fill(1, 7, 0);
        $notes         = [];

        foreach ($known as [$row, $ownerKey, $amount]) {
            $month = $row->spentAt->format('Y-m');
            $day   = $row->spentAt->format('Y-m-d');
            $total += $amount;

            $categoryTotal[$row->categoryId]            = ($categoryTotal[$row->categoryId] ?? 0) + $amount;
            $ownerCategory[$ownerKey][$row->categoryId] = ($ownerCategory[$ownerKey][$row->categoryId] ?? 0)
                + $amount;
            $payerOwner[$row->payerId][$ownerKey]    = ($payerOwner[$row->payerId][$ownerKey] ?? 0) + $amount;
            $monthOwner[$month][$ownerKey]           = ($monthOwner[$month][$ownerKey] ?? 0) + $amount;
            $monthCategory[$month][$row->categoryId] = ($monthCategory[$month][$row->categoryId] ?? 0)
                + $amount;
            $days[$day] = ($days[$day] ?? 0) + $amount;
            $weekdays[(int) $row->spentAt->format('N')] += $amount;

            $note = $this->noteKey($row->note);

            if ($note !== null) {
                $notes[$note] ??= [0, 0];
                $notes[$note][0] += $amount;
                ++$notes[$note][1];
            }
        }

        arsort($categoryTotal);
        ksort($days);

        return new SpendingAnalysis(
            $period,
            $base,
            $owners,
            $filter,
            $categories,
            $total,
            count($known),
            $pending,
            intdiv($total, $period->elapsedDays($today)),
            $ownerTotals,
            $categoryTotal,
            $ownerCategory,
            $payerOwner,
            $monthOwner,
            $monthCategory,
            $days,
            $weekdays,
            $this->topExpenses($known),
            $this->topNotes($notes),
        );
    }

    /**
     * @param list<Category> $categories
     *
     * @return array<int, Category>
     */
    private function indexCategories(array $categories): array
    {
        $indexed = [];

        foreach ($categories as $category) {
            $indexed[$category->id] = $category;
        }

        return $indexed;
    }

    /**
     * @param list<array{SpendingRow, string, int}> $known
     *
     * @return list<SpendingRow>
     */
    private function topExpenses(array $known): array
    {
        usort($known, static fn (array $a, array $b): int => [$b[2], $b[0]->id] <=> [$a[2], $a[0]->id]);

        return array_map(
            static fn (array $item): SpendingRow => $item[0],
            array_slice($known, 0, self::TOP_EXPENSES),
        );
    }

    /**
     * @param array<string, array{int, int}> $notes
     *
     * @return list<NoteTotal>
     */
    private function topNotes(array $notes): array
    {
        $totals = [];

        foreach ($notes as $note => [$amount, $count]) {
            $totals[] = new NoteTotal((string) $note, $amount, $count);
        }

        usort(
            $totals,
            static fn (NoteTotal $a, NoteTotal $b): int => [$b->totalMinor, $a->note] <=> [$a->totalMinor, $b->note],
        );

        return array_slice($totals, 0, self::TOP_NOTES);
    }

    private function noteKey(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $note) ?? ''));

        return $key === '' ? null : $key;
    }
}
