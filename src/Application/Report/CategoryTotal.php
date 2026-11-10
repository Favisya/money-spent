<?php

declare(strict_types=1);

namespace App\Application\Report;

use App\Domain\Money\Money;

final readonly class CategoryTotal
{
    public function __construct(
        public string $title,
        public Money $amount,
        public int $count,
    ) {
    }
}
