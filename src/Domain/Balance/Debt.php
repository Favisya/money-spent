<?php

declare(strict_types=1);

namespace App\Domain\Balance;

use InvalidArgumentException;

/**
 * Debtor owes creditor the amount (always positive, base minor units).
 */
final readonly class Debt
{
    public function __construct(
        public int $debtorId,
        public int $creditorId,
        public int $amountBaseMinor,
    ) {
        if ($debtorId === $creditorId) {
            throw new InvalidArgumentException('Debtor and creditor must differ.');
        }

        if ($amountBaseMinor <= 0) {
            throw new InvalidArgumentException('Debt amount must be positive.');
        }
    }
}
