<?php

declare(strict_types=1);

namespace App\Domain\Balance;

final readonly class Balance
{
    /**
     * @param list<Debt>      $debts
     * @param array<int, int> $netByParticipant positive: is owed money, negative: owes money
     */
    public function __construct(
        public array $debts,
        public array $netByParticipant,
    ) {
    }

    public function isSettled(): bool
    {
        return $this->debts === [];
    }
}
