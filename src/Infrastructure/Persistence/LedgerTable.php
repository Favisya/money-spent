<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

/**
 * Whitelist of tables that hold money movements; the only source of table/column identifiers in dynamic queries.
 */
enum LedgerTable: string
{
    case Expense    = 'expense';
    case Settlement = 'settlement';

    public function authorColumn(): string
    {
        return match ($this) {
            self::Expense    => 'payer_id',
            self::Settlement => 'from_id',
        };
    }

    public function dateColumn(): string
    {
        return match ($this) {
            self::Expense    => 'spent_at',
            self::Settlement => 'settled_at',
        };
    }
}
