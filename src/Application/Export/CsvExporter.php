<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Application\Expense\ExpenseView;
use App\Application\Report\MonthPeriod;
use App\Infrastructure\Persistence\ExpenseRepository;
use RuntimeException;

final readonly class CsvExporter
{
    private const array HEADER = [
        'id',
        'date',
        'payer',
        'category',
        'amount',
        'currency',
        'rate_to_base',
        'amount_base',
        'split',
        'note',
    ];

    private const string FORMULA_TRIGGERS = "=+-@\t\r";

    public function __construct(
        private ExpenseRepository $expenses,
    ) {
    }

    /**
     * @return string|null CSV content, null when the month has no expenses
     */
    public function export(MonthPeriod $period): ?string
    {
        $rows = $this->expenses->findViews($period->start(), $period->endExclusive());

        if ($rows === []) {
            return null;
        }

        $stream = fopen('php://temp', 'r+') ?: throw new RuntimeException('Cannot open temp stream.');
        fwrite($stream, "\u{FEFF}");
        fputcsv($stream, self::HEADER, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, $this->toRow($row), ';', '"', '');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content === false ? throw new RuntimeException('Cannot read CSV stream.') : $content;
    }

    /**
     * @return list<string>
     */
    private function toRow(ExpenseView $expense): array
    {
        return [
            (string) $expense->id,
            $expense->spentAt->format('Y-m-d'),
            self::text($expense->payerName),
            self::text($expense->categoryTitle),
            $expense->amount->toDecimal(),
            $expense->amount->currency->code,
            $expense->rate ?? '',
            $expense->amountBase?->toDecimal() ?? '',
            $expense->split->value,
            self::text($expense->note ?? ''),
        ];
    }

    /**
     * Neutralizes spreadsheet formula injection in free-text cells.
     */
    private static function text(string $value): string
    {
        if ($value !== '' && str_contains(self::FORMULA_TRIGGERS, $value[0])) {
            return "'" . $value;
        }

        return $value;
    }
}
