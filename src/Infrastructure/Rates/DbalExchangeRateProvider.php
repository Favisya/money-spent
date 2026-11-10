<?php

declare(strict_types=1);

namespace App\Infrastructure\Rates;

use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Currency;
use App\Domain\Money\ExchangeRate;
use App\Domain\Money\ExchangeRateProvider;
use App\Domain\Money\RateNotFoundException;
use App\Infrastructure\Persistence\ExchangeRateRepository;
use DateTimeImmutable;

final readonly class DbalExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(
        private ExchangeRateRepository $rates,
        private CurrencyCatalog $currencies,
    ) {
    }

    public function rateFor(Currency $currency, DateTimeImmutable $date): ExchangeRate
    {
        $base = $this->currencies->base();

        if ($currency->equals($base)) {
            return ExchangeRate::identity($base, $date);
        }

        $found = $this->rates->latestOnOrBefore($currency->code, $date);

        if ($found === null) {
            throw RateNotFoundException::for($currency, $date);
        }

        return new ExchangeRate($currency, $base, $found['rate'], $found['date']);
    }
}
