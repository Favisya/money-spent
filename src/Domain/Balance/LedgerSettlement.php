<?php

declare(strict_types=1);

namespace App\Domain\Balance;

use InvalidArgumentException;

final readonly class LedgerSettlement
{
    public function __construct(
        public int $fromId,
        public int $toId,
        public int $amountBaseMinor,
    ) {
        if ($fromId === $toId) {
            throw new InvalidArgumentException('Settlement sender and receiver must differ.');
        }

        if ($amountBaseMinor <= 0) {
            throw new InvalidArgumentException('Settlement amount must be positive.');
        }
    }
}
