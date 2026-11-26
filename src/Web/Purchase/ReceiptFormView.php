<?php

declare(strict_types=1);

namespace App\Web\Purchase;

use App\Application\Purchase\ReceiptFormReader;
use App\Application\Purchase\ReceiptView;
use App\Domain\Purchase\ReceiptLine;

/**
 * The receipt editor's values as strings: from the stored receipt, or echoed back after a failed save.
 */
final class ReceiptFormView
{
    public const int BLANK_ROWS = 3;

    /**
     * @return array{fields: array<string, string>, rows: list<array<string, string>>}
     */
    public static function fromReceipt(ReceiptView $receipt, ?int $lastPayerId): array
    {
        $content = $receipt->content;
        $payer   = match (true) {
            $receipt->paidByBoth       => ReceiptFormReader::BOTH,
            $receipt->payerId !== null => (string) $receipt->payerId,
            $lastPayerId !== null      => (string) $lastPayerId,
            default                    => '',
        };

        return [
            'fields' => [
                'store'         => $content->store ?? '',
                'date'          => $content->purchasedAt->format('Y-m-d'),
                'currency'      => $content->basket->currency->code,
                'printed_total' => $content->printedTotal?->toDecimal() ?? '',
                'delivery'      => $content->delivery?->toDecimal() ?? '',
                'payer'         => $payer,
                'split'         => $receipt->split->value,
            ],
            'rows' => self::withBlankRows(array_map(self::row(...), $content->basket->lines)),
        ];
    }

    /**
     * @param array<mixed> $posted
     *
     * @return array{fields: array<string, string>, rows: list<array<string, string>>}
     */
    public static function fromPost(array $posted): array
    {
        $fields = [];

        foreach (['store', 'date', 'currency', 'printed_total', 'delivery', 'payer', 'split'] as $key) {
            $fields[$key] = is_string($posted[$key] ?? null) ? $posted[$key] : '';
        }

        $rows = [];

        foreach (is_array($posted['items'] ?? null) ? $posted['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rows[] = array_map(
                static fn (mixed $value): string => is_string($value) ? $value : '',
                array_intersect_key($item, array_flip(['name', 'quantity', 'unit', 'price', 'amount', 'remove'])),
            );

            if (count($rows) >= ReceiptFormReader::MAX_ROWS) {
                break;
            }
        }

        return ['fields' => $fields, 'rows' => $rows];
    }

    /**
     * @return array<string, string>
     */
    private static function row(ReceiptLine $line): array
    {
        return [
            'name'     => $line->name,
            'quantity' => $line->quantity->format(),
            'unit'     => $line->quantity->unit->value,
            'price'    => $line->unitPrice?->toDecimal() ?? '',
            'amount'   => $line->amount->toDecimal(),
        ];
    }

    /**
     * @param list<array<string, string>> $rows
     *
     * @return list<array<string, string>>
     */
    private static function withBlankRows(array $rows): array
    {
        $blank = ['name' => '', 'quantity' => '', 'unit' => 'pcs', 'price' => '', 'amount' => ''];

        return [...$rows, ...array_fill(0, self::BLANK_ROWS, $blank)];
    }
}
