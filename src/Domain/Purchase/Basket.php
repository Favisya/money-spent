<?php

declare(strict_types=1);

namespace App\Domain\Purchase;

use App\Domain\Money\Currency;
use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;

/**
 * The positions of one receipt, all in its currency.
 */
final readonly class Basket
{
    /**
     * @param list<ReceiptLine> $lines
     */
    public function __construct(
        public Currency $currency,
        public array $lines,
    ) {
        foreach ($lines as $line) {
            if (!$line->amount->currency->equals($currency)) {
                throw CurrencyMismatchException::between($line->amount->currency, $currency);
            }
        }
    }

    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            static fn (Money $total, ReceiptLine $line): Money => $total->add($line->amount),
            Money::zero($this->currency),
        );
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
