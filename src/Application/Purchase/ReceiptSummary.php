<?php

declare(strict_types=1);

namespace App\Application\Purchase;

use App\Domain\Money\Money;
use DateTimeImmutable;

final readonly class ReceiptSummary
{
    public function __construct(
        public int $id,
        public ReceiptStatus $status,
        public ?string $store,
        public DateTimeImmutable $purchasedAt,
        public Money $total,
        public int $itemCount,
        public ?string $payerName,
        public bool $paidByBoth,
        public bool $hasExpense,
        public bool $hasImage,
    ) {
    }
}
