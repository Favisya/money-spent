<?php

declare(strict_types=1);

namespace App\Application\Rates;

use DateTimeImmutable;

final readonly class RateSnapshot
{
    /**
     * @param DateTimeImmutable $effectiveDate the date the source says these rates belong to (may precede the request)
     * @param list<RateQuote>   $quotes
     */
    public function __construct(
        public DateTimeImmutable $effectiveDate,
        public array $quotes,
    ) {
    }
}
