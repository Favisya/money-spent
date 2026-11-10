<?php

declare(strict_types=1);

namespace App\Application\Rates;

use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Currency;
use App\Domain\Money\ExchangeRate;
use App\Domain\Money\ExchangeRateProvider;
use App\Domain\Money\RateNotFoundException;
use DateTimeImmutable;

final readonly class RateResolver
{
    public function __construct(
        private ExchangeRateProvider $provider,
        private CurrencyCatalog $currencies,
    ) {
    }

    public function find(Currency $currency, DateTimeImmutable $date): ?ExchangeRate
    {
        $base = $this->currencies->base();

        if ($currency->equals($base)) {
            return ExchangeRate::identity($base, $date);
        }

        try {
            return $this->provider->rateFor($currency, $date);
        } catch (RateNotFoundException) {
            return null;
        }
    }
}
