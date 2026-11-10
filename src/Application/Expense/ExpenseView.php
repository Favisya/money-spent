<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class ExpenseView
{
    public function __construct(
        public int $id,
        public int $payerId,
        public string $payerName,
        public DateTimeImmutable $spentAt,
        public Money $amount,
        public ?string $rate,
        public ?Money $amountBase,
        public int $categoryId,
        public string $categoryTitle,
        public SplitType $split,
        public ?string $note,
        public bool $deleted,
        public ?int $recurringId = null,
    ) {
    }
}
