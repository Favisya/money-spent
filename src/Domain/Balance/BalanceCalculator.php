<?php

declare(strict_types=1);

namespace App\Domain\Balance;

use App\Domain\Split\SplitCalculator;
use InvalidArgumentException;

final readonly class BalanceCalculator
{
    public function __construct(
        private SplitCalculator $splitCalculator,
    ) {
    }

    /**
     * @param list<int>                  $participantIds
     * @param iterable<LedgerExpense>    $expenses
     * @param iterable<LedgerSettlement> $settlements
     */
    public function calculate(array $participantIds, iterable $expenses, iterable $settlements): Balance
    {
        $net = array_fill_keys($participantIds, 0);

        foreach ($expenses as $expense) {
            $shares = $this->splitCalculator->shares(
                $expense->payerId,
                $expense->amountBaseMinor,
                $expense->split,
                $participantIds,
            );

            $net[$expense->payerId] += $expense->amountBaseMinor;

            foreach ($shares as $participantId => $share) {
                $net[$participantId] -= $share;
            }
        }

        foreach ($settlements as $settlement) {
            $this->assertKnown($net, $settlement->fromId);
            $this->assertKnown($net, $settlement->toId);

            $net[$settlement->fromId] += $settlement->amountBaseMinor;
            $net[$settlement->toId] -= $settlement->amountBaseMinor;
        }

        return new Balance($this->resolveDebts($net), $net);
    }

    /**
     * Greedy matching of the largest debtor with the largest creditor; ties broken by participant id.
     *
     * @param array<int, int> $net
     *
     * @return list<Debt>
     */
    private function resolveDebts(array $net): array
    {
        $creditors = array_filter($net, static fn (int $amount): bool => $amount > 0);
        $debtors   = array_map(
            static fn (int $amount): int => -$amount,
            array_filter($net, static fn (int $amount): bool => $amount < 0),
        );
        $debts = [];

        while ($creditors !== [] && $debtors !== []) {
            $creditorId = $this->largest($creditors);
            $debtorId   = $this->largest($debtors);
            $amount     = min($creditors[$creditorId], $debtors[$debtorId]);
            $debts[]    = new Debt($debtorId, $creditorId, $amount);

            $creditors[$creditorId] -= $amount;
            $debtors[$debtorId] -= $amount;

            if ($creditors[$creditorId] === 0) {
                unset($creditors[$creditorId]);
            }

            if ($debtors[$debtorId] === 0) {
                unset($debtors[$debtorId]);
            }
        }

        return $debts;
    }

    /**
     * @param non-empty-array<int, int> $amounts
     */
    private function largest(array $amounts): int
    {
        $bestId = null;

        foreach ($amounts as $id => $amount) {
            if ($bestId === null || $amount > $amounts[$bestId] || ($amount === $amounts[$bestId] && $id < $bestId)) {
                $bestId = $id;
            }
        }

        return (int) $bestId;
    }

    /**
     * @param array<int, int> $net
     */
    private function assertKnown(array $net, int $participantId): void
    {
        if (!array_key_exists($participantId, $net)) {
            throw new InvalidArgumentException(sprintf('Unknown participant %d.', $participantId));
        }
    }
}
