<?php

declare(strict_types=1);

namespace App\Domain\Purchase;

use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * One position of a receipt: what, how much, the price per unit and the line amount.
 * A negative amount is a discount line.
 */
final readonly class ReceiptLine
{
    public const int NAME_LENGTH = 255;

    public string $name;

    private function __construct(
        string $name,
        public Quantity $quantity,
        public ?Money $unitPrice,
        public Money $amount,
    ) {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '') {
            throw new InvalidArgumentException('A receipt line needs a name.');
        }

        if ($unitPrice !== null && !$unitPrice->currency->equals($amount->currency)) {
            throw CurrencyMismatchException::between($unitPrice->currency, $amount->currency);
        }

        $this->name = mb_substr($name, 0, self::NAME_LENGTH);
    }

    /**
     * Without an amount it is the unit price times the quantity; at least one of the two is needed.
     */
    public static function of(string $name, Quantity $quantity, ?Money $unitPrice, ?Money $amount): self
    {
        if ($amount === null && $unitPrice === null) {
            throw new InvalidArgumentException('A receipt line needs a price or an amount.');
        }

        return new self($name, $quantity, $unitPrice, $amount ?? $unitPrice->multiply($quantity->value));
    }
}
