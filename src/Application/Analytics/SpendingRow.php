<?php

declare(strict_types=1);

namespace App\Application\Analytics;

use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class SpendingRow
{
    /**
     * @param int|null $amountBaseMinor null while the exchange rate is unknown
     */
    public function __construct(
        public int $id,
        public int $payerId,
        public DateTimeImmutable $spentAt,
        public int $categoryId,
        public SplitType $split,
        public Money $amount,
        public ?int $amountBaseMinor,
        public ?string $note,
    ) {
    }
}
