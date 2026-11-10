<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Application\Participant\Participant;
use App\Domain\Money\Money;

/**
 * The balance between two people drawn as a track: the filled half points to whoever is owed.
 */
final readonly class DebtLine
{
    public function __construct(
        public Participant $left,
        public Participant $right,
        public ?Participant $debtor,
        public ?Participant $creditor,
        public Money $amount,
        public int $percent,
    ) {
    }

    public function isSettled(): bool
    {
        return $this->creditor === null;
    }

    public function creditorIsLeft(): bool
    {
        return $this->creditor?->id === $this->left->id;
    }
}
