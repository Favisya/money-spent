<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Purchase\ReadingContext;
use App\Application\Purchase\ReceiptInput;
use App\Domain\Purchase\Unit;

/**
 * The instructions and the answer schema shared by every receipt reader, so all models are asked the same way.
 */
final class ReceiptPrompt
{
    private const array LANGUAGES = ['ru' => 'Russian', 'en' => 'English'];

    public static function instructions(ReadingContext $context): string
    {
        $language = self::LANGUAGES[$context->language] ?? self::LANGUAGES['ru'];

        return <<<TEXT
            You read shop receipts (photos), screenshots of orders from delivery apps (Yandex Lavka, Yandex Eda,
            Glovo, Wolt, Menu.am and the like) and shopping lists typed by hand, and return the purchased positions.

            Rules:
            - One item per purchased product. Skip subtotals, totals, taxes, payment method, change, card numbers,
              loyalty points, cashier and fiscal data.
            - name: a clear product name in {$language}. When the receipt or the list is in another language
              (Armenian, Russian, English or any other), translate the name into {$language}. Expand receipt
              abbreviations into a normal product name; keep brand names as they are.
            - quantity: a number with a dot as the decimal separator, e.g. "1", "2", "0.746". Weighed goods carry
              their weight, packaged goods the count.
            - unit: "pcs" for pieces and packages, "kg" or "g" for weight, "l" or "ml" for volume, as the receipt
              measures the item.
            - unit_price: the price of one unit as printed; amount: the line total as printed. Digits with a dot
              for decimals, a minus sign for discounts, no thousand separators, no currency signs. Use "" when the
              value is not there.
            - A discount is its own item with a negative amount and an empty unit_price.
            - delivery: what the order charges on top of the goods (delivery, courier, service or packaging fee, tip),
              summed into one amount, "" when there is none. These charges are never items. A free delivery is "".
            - In an app screenshot take the price actually charged, not a crossed-out old price, and the count shown
              next to the item (×2, 2 pcs) as its quantity. store: the shop or the app name as it is written there.
            - store: the shop name when visible. date: the purchase date as YYYY-MM-DD when printed, otherwise "".
              currency: the ISO 4217 code (Armenian dram is AMD, rouble RUB, euro EUR), "" when unclear.
              total: the amount to pay printed on the receipt or shown in the app, delivery included, "" when there
              is none.
            - In a typed list every line is an item; a single number after the name is the line amount, a number
              with a unit is the quantity.
            - Never invent items or numbers that are not there.

            Today is {$context->today->format('Y-m-d')}. If no currency is printed, prices are in
            {$context->defaultCurrency->code}.
            TEXT;
    }

    public static function request(ReceiptInput $input): string
    {
        if ($input->text === null) {
            return 'Read the positions of this receipt or order.';
        }

        return "Read the positions of this shopping list:\n<list>\n" . $input->text . "\n</list>";
    }

    /**
     * Strings everywhere: amounts stay exact decimals, and "" marks a missing value without nullable types.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['store', 'date', 'currency', 'total', 'delivery', 'items'],
            'properties'           => [
                'store'    => $text,
                'date'     => $text,
                'currency' => $text,
                'total'    => $text,
                'delivery' => $text,
                'items'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name', 'quantity', 'unit', 'unit_price', 'amount'],
                        'properties'           => [
                            'name'     => $text,
                            'quantity' => $text,
                            'unit'     => [
                                'type' => 'string',
                                'enum' => array_map(static fn (Unit $unit): string => $unit->value, Unit::cases()),
                            ],
                            'unit_price' => $text,
                            'amount'     => $text,
                        ],
                    ],
                ],
            ],
        ];
    }
}
