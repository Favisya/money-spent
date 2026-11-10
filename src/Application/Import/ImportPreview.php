<?php

declare(strict_types=1);

namespace App\Application\Import;

final readonly class ImportPreview
{
    /**
     * @param list<ImportRow> $rows
     */
    public function __construct(
        public array $rows,
    ) {
    }

    /**
     * @return list<ImportRow>
     */
    public function expenses(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => $row->kind === ImportRowKind::Expense,
        ));
    }

    /**
     * @return list<ImportRow>
     */
    public function transfers(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => $row->kind === ImportRowKind::Transfer,
        ));
    }

    /**
     * @return list<ImportRow> expenses and transfers in note order
     */
    public function records(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => in_array(
                $row->kind,
                [ImportRowKind::Expense, ImportRowKind::Transfer],
                true,
            ),
        ));
    }

    public function errorCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (ImportRow $row): bool => $row->kind === ImportRowKind::Error,
        ));
    }
}
