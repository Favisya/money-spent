<?php

declare(strict_types=1);

namespace App\Domain\Balance;

use App\Domain\Split\SplitType;

final readonly class LedgerExpense
{
    public function __construct(
        public int $payerId,
        public int $amountBaseMinor,
        public SplitType $split,
    ) {
    }
}
