<?php

declare(strict_types=1);

namespace App\Application\Rates;

final readonly class RateQuote
{
    /**
     * @param string $rateToBase decimal string, base currency units per one unit of the currency
     */
    public function __construct(
        public string $currencyCode,
        public string $rateToBase,
    ) {
    }
}
