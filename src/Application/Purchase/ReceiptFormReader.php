<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Application\Clock\LocalCalendar;
use App\Application\Reference\CurrencyCatalog;
use App\Application\UserError;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Purchase\Basket;
use App\Domain\Purchase\Quantity;
use App\Domain\Purchase\ReceiptLine;
use App\Domain\Purchase\Unit;
use App\Domain\Split\SplitType;
use App\Infrastructure\Persistence\ParticipantRepository;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validates the posted receipt editor. Messages name the row so the person can find the mistake.
 */
final readonly class ReceiptFormReader
{
    public const string BOTH     = 'both';
    public const int MAX_ROWS    = 300;
    private const int TEXT_LIMIT = 300;

    public function __construct(
        private CurrencyCatalog $currencies,
        private ParticipantRepository $participants,
        private LocalCalendar $calendar,
    ) {
    }

    /**
     * @param array<mixed> $fields the posted form
     */
    public function read(array $fields): ReceiptForm
    {
        $currency = $this->currency(self::text($fields, 'currency'));
        $rows     = is_array($fields['items'] ?? null) ? $fields['items'] : [];
        $basket   = new Basket($currency, $this->lines($rows, $currency));

        if ($basket->isEmpty()) {
            throw new UserError('Добавь хотя бы одну позицию.');
        }

        $store   = mb_substr(self::text($fields, 'store'), 0, ReceiptNormalizer::STORE_LENGTH);
        $printed = self::text($fields, 'printed_total');
        $content = new ReceiptContent(
            $store === '' ? null : $store,
            $this->date(self::text($fields, 'date')),
            $basket,
            $printed === '' ? null : $this->money($printed, $currency, 'Итог по чеку — число, например 12500.'),
            $this->delivery(self::text($fields, 'delivery'), $currency),
        );

        if (!$content->total()->isPositive()) {
            throw new UserError('Итог чека должен быть больше нуля.');
        }

        return new ReceiptForm(
            $content,
            $this->payer(self::text($fields, 'payer')),
            SplitType::tryFrom(self::text($fields, 'split')) ?? SplitType::Equal,
        );
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<ReceiptLine>
     */
    private function lines(array $rows, Currency $currency): array
    {
        $lines  = [];
        $number = 0;

        foreach (array_slice(array_values($rows), 0, self::MAX_ROWS) as $row) {
            if (!is_array($row)) {
                continue;
            }

            ++$number;
            $name     = self::text($row, 'name');
            $quantity = self::text($row, 'quantity');
            $price    = self::text($row, 'price');
            $amount   = self::text($row, 'amount');

            if (self::text($row, 'remove') === '1' || ($name . $quantity . $price . $amount) === '') {
                continue;
            }

            $lines[] = $this->line(
                $number,
                $name,
                $quantity,
                Unit::tryFrom(self::text($row, 'unit')),
                $price,
                $amount,
                $currency,
            );
        }

        return $lines;
    }

    private function line(
        int $number,
        string $name,
        string $quantity,
        ?Unit $unit,
        string $price,
        string $amount,
        Currency $currency,
    ): ReceiptLine {
        $row = ['%row%' => $number];

        if ($name === '') {
            throw new UserError('Строка %row%: нужно название.', $row);
        }

        try {
            $parsedQuantity = Quantity::of($quantity === '' ? '1' : $quantity, $unit ?? Unit::Piece);
        } catch (InvalidArgumentException) {
            throw new UserError('Строка %row%: количество — число больше нуля, штуки — целым числом.', $row);
        }

        $unitPrice = $price === ''
            ? null
            : $this->money($price, $currency, 'Строка %row%: цена — число, например 1200 или 18,5.', $row);
        $sum = $amount === ''
            ? null
            : $this->money($amount, $currency, 'Строка %row%: сумма — число, например 1200 или -200 для скидки.', $row);

        if ($unitPrice === null && $sum === null) {
            throw new UserError('Строка %row%: укажи цену или сумму.', $row);
        }

        return ReceiptLine::of($name, $parsedQuantity, $unitPrice, $sum);
    }

    /**
     * @param array<string, int> $parameters
     */
    private function money(string $text, Currency $currency, string $error, array $parameters = []): Money
    {
        return MoneyText::parse($text, $currency) ?? throw new UserError($error, $parameters);
    }

    /**
     * Delivery, service fee and the like, paid on top of the positions; empty or zero means none.
     */
    private function delivery(string $text, Currency $currency): ?Money
    {
        if ($text === '') {
            return null;
        }

        $delivery = $this->money($text, $currency, 'Доставка — число не меньше нуля, например 500.');

        if ($delivery->isNegative()) {
            throw new UserError('Доставка — число не меньше нуля, например 500.');
        }

        return $delivery->isZero() ? null : $delivery;
    }

    private function currency(string $code): Currency
    {
        try {
            return $this->currencies->get(strtoupper($code));
        } catch (InvalidArgumentException) {
            throw new UserError('Такой валюты нет.');
        }
    }

    private function date(string $text): DateTimeImmutable
    {
        $today = $this->calendar->today();
        $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $today->getTimezone());

        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new UserError('Укажи дату покупки.');
        }

        if ($date > $today) {
            throw new UserError('Дата чека ещё не наступила.');
        }

        return $date;
    }

    private function payer(string $choice): ?int
    {
        if ($choice === self::BOTH) {
            return null;
        }

        $participant = ctype_digit($choice) ? $this->participants->find((int) $choice) : null;

        if ($participant === null) {
            throw new UserError('Выбери, кто платил.');
        }

        return $participant->id;
    }

    /**
     * @param array<mixed> $fields
     */
    private static function text(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return is_string($value) ? mb_substr(trim($value), 0, self::TEXT_LIMIT) : '';
    }
}
