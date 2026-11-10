<?php

declare(strict_types=1);

namespace App\Domain\Money;

use DateTimeImmutable;

interface ExchangeRateProvider
{
    /**
     * Returns the rate effective on the given date (the nearest previous one when the date itself has none).
     *
     * @throws RateNotFoundException
     */
    public function rateFor(Currency $currency, DateTimeImmutable $date): ExchangeRate;
}
