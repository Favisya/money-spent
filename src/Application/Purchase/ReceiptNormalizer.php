<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Purchase\Basket;
use App\Domain\Purchase\Quantity;
use App\Domain\Purchase\ReceiptLine;
use App\Domain\Purchase\Unit;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Turns whatever the model answered into a reviewable draft: unknown values fall back to safe defaults,
 * nothing here throws on a sloppy answer. The person checks the result before it becomes an expense.
 */
final class ReceiptNormalizer
{
    public const int STORE_LENGTH = 128;

    private const string OLDEST = '-1 year';

    public function normalize(RecognizedReceipt $receipt, ReadingContext $context): ReceiptContent
    {
        $currency = $context->currencies[strtoupper($receipt->currency)] ?? $context->defaultCurrency;
        $lines    = [];

        foreach ($receipt->lines as $line) {
            $normalized = $this->line($line, $currency);

            if ($normalized !== null) {
                $lines[] = $normalized;
            }
        }

        $store    = mb_substr(trim($receipt->store), 0, self::STORE_LENGTH);
        $delivery = MoneyText::parse($receipt->delivery, $currency);

        return new ReceiptContent(
            $store === '' ? null : $store,
            $this->date($receipt->date, $context->today),
            new Basket($currency, $lines),
            MoneyText::parse($receipt->total, $currency),
            $delivery !== null && $delivery->isPositive() ? $delivery : null,
        );
    }

    private function line(RecognizedLine $line, Currency $currency): ?ReceiptLine
    {
        if (trim($line->name) === '') {
            return null;
        }

        $unitPrice = MoneyText::parse($line->unitPrice, $currency);
        $amount    = MoneyText::parse($line->amount, $currency);

        if ($unitPrice === null && $amount === null) {
            $amount = Money::zero($currency);
        }

        return ReceiptLine::of($line->name, $this->quantity($line), $unitPrice, $amount);
    }

    private function quantity(RecognizedLine $line): Quantity
    {
        $unit = Unit::fromText($line->unit) ?? Unit::Piece;

        try {
            return Quantity::of($line->quantity === '' ? '1' : $line->quantity, $unit);
        } catch (InvalidArgumentException) {
            return Quantity::one();
        }
    }

    /**
     * The printed date when it is real, not ahead and not older than a year; otherwise today.
     */
    private function date(string $text, DateTimeImmutable $today): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $today->getTimezone());

        if ($date === false || $date->format('Y-m-d') !== $text) {
            return $today;
        }

        return $date > $today || $date < $today->modify(self::OLDEST) ? $today : $date;
    }
}
