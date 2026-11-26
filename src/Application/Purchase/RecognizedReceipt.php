<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\UserError;

/**
 * The model's answer, shaped by ReceiptSchema. Every value is a string, empty when unknown.
 */
final readonly class RecognizedReceipt
{
    public const int MAX_LINES = 300;

    /**
     * @param list<RecognizedLine> $lines
     */
    public function __construct(
        public string $store,
        public string $date,
        public string $currency,
        public string $total,
        public array $lines,
        public string $delivery = '',
    ) {
    }

    /**
     * @param array<mixed> $data decoded JSON
     */
    public static function fromArray(array $data): self
    {
        $items = $data['items'] ?? null;

        if (!is_array($items)) {
            throw new UserError('ИИ ответил не по формату — попробуй ещё раз.');
        }

        $lines = [];

        foreach (array_slice($items, 0, self::MAX_LINES) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = new RecognizedLine(
                self::text($item, 'name'),
                self::text($item, 'quantity'),
                self::text($item, 'unit'),
                self::text($item, 'unit_price'),
                self::text($item, 'amount'),
            );
        }

        return new self(
            self::text($data, 'store'),
            self::text($data, 'date'),
            self::text($data, 'currency'),
            self::text($data, 'total'),
            $lines,
            self::text($data, 'delivery'),
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
    }
}
