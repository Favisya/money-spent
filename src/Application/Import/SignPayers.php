<?php

declare(strict_types=1);

namespace App\Application\Import;

/**
 * Note convention where the sign tells who paid ("+9700 ..." one person, "-21000 ..." the other) instead of marking
 * a refund. Amounts are imported as positive expenses.
 */
final readonly class SignPayers
{
    public function __construct(
        public int $plusPayerId,
        public int $minusPayerId,
    ) {
    }

    public function payerFor(string $sign): int
    {
        return $sign === '-' ? $this->minusPayerId : $this->plusPayerId;
    }
}
