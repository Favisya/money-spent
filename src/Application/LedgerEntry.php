<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Money\Money;
use App\Infrastructure\Persistence\LedgerTable;
use DateTimeImmutable;

/**
 * Minimal view of an expense or settlement row, shared by undo and pending-rate handling.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public LedgerTable $table,
        public int $id,
        public Money $amount,
        public DateTimeImmutable $date,
        public ?string $note,
    ) {
    }
}
