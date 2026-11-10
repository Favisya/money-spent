<?php

declare(strict_types=1);

namespace App\Application\Recurring;

use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;

final readonly class RecurringDraft
{
    public function __construct(
        public ?int $payerId,
        public Money $amount,
        public int $categoryId,
        public SplitType $split,
        public ?string $note,
        public int $dayOfMonth,
        public DateTimeImmutable $nextMonth,
    ) {
    }
}
