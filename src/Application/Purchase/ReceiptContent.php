<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Money\CurrencyMismatchException;
use App\Domain\Money\Money;
use App\Domain\Purchase\Basket;
use DateTimeImmutable;

/**
 * What a receipt says: where, when, the positions, and what the order charged on top of them (delivery, service).
 */
final readonly class ReceiptContent
{
    public function __construct(
        public ?string $store,
        public DateTimeImmutable $purchasedAt,
        public Basket $basket,
        public ?Money $printedTotal,
        public ?Money $delivery = null,
    ) {
        if ($delivery !== null && !$delivery->currency->equals($basket->currency)) {
            throw CurrencyMismatchException::between($delivery->currency, $basket->currency);
        }
    }

    /**
     * What was paid: the positions plus delivery. The expense is recorded for this amount.
     */
    public function total(): Money
    {
        $items = $this->basket->total();

        return $this->delivery === null ? $items : $items->add($this->delivery);
    }

    /**
     * How far the paid total is from the one printed on the receipt; null when nothing is printed.
     */
    public function differenceFromPrinted(): ?Money
    {
        return $this->printedTotal?->subtract($this->total());
    }
}
