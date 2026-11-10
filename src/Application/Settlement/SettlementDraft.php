<?php

declare(strict_types=1);

namespace App\Application\Settlement;

use App\Domain\Money\ExchangeRate;
use App\Domain\Money\Money;
use DateTimeImmutable;

final readonly class SettlementDraft
{
    public function __construct(
        public string $uuid,
        public int $fromId,
        public int $toId,
        public Money $amount,
        public ?ExchangeRate $rate,
        public DateTimeImmutable $settledAt,
        public ?string $note,
    ) {
    }

    public function amountBase(): ?Money
    {
        return $this->rate?->convert($this->amount);
    }
}
