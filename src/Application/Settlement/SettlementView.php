<?php

declare(strict_types=1);

namespace App\Application\Settlement;

use App\Domain\Money\Money;
use DateTimeImmutable;

final readonly class SettlementView
{
    public function __construct(
        public int $id,
        public string $fromName,
        public string $toName,
        public Money $amount,
        public ?Money $amountBase,
        public DateTimeImmutable $settledAt,
        public ?string $note,
        public int $fromId = 0,
        public int $toId = 0,
    ) {
    }
}
