<?php

declare(strict_types=1);

namespace App\Web\Currency;

use DateTimeImmutable;

/**
 * "100 AMD = 22.96 RUB": how much of the display currency a round amount of another currency is worth.
 */
final readonly class DisplayQuote
{
    public function __construct(
        public string $currency,
        public int $nominal,
        public string $value,
        public string $displayCurrency,
        public DateTimeImmutable $date,
    ) {
    }
}
