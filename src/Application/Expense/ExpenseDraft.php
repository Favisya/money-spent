<?php

declare(strict_types=1);

namespace App\Application\Expense;

use App\Domain\Money\ExchangeRate;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class ExpenseDraft
{
    public function __construct(
        public string $uuid,
        public int $payerId,
        public DateTimeImmutable $spentAt,
        public Money $amount,
        public ?ExchangeRate $rate,
        public int $categoryId,
        public SplitType $split,
        public ?string $note,
        public ?int $recurringId = null,
    ) {
    }

    public function amountBase(): ?Money
    {
        return $this->rate?->convert($this->amount);
    }
}
