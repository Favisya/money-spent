<?php

declare(strict_types=1);

namespace App\Application\Recurring;

use App\Domain\Money\Money;
use App\Domain\Recurring\MonthlyRecurrence;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class RecurringExpense
{
    public function __construct(
        public int $id,
        public ?int $payerId,
        public ?string $payerName,
        public Money $amount,
        public int $categoryId,
        public string $categoryCode,
        public string $categoryTitle,
        public SplitType $split,
        public ?string $note,
        public int $dayOfMonth,
        public DateTimeImmutable $nextMonth,
        public bool $active,
    ) {
    }

    /**
     * Both participants pay, half each.
     */
    public function isJoint(): bool
    {
        return $this->payerId === null;
    }

    public function nextDate(): DateTimeImmutable
    {
        return (new MonthlyRecurrence($this->dayOfMonth))->dateIn($this->nextMonth);
    }
}
