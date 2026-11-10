<?php

declare(strict_types=1);

namespace App\Application\Rates;

use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Currency;
use App\Domain\Money\ExchangeRate;
use App\Domain\Money\ExchangeRateProvider;
use App\Domain\Money\RateNotFoundException;
use App\Infrastructure\Persistence\MarketRateRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Rates for showing totals in another currency, from the source the viewer picked. The official CBA rates live in
 * their own table; other sources are fetched daily into market_rate.
 */
final readonly class MarketRates
{
    public function __construct(
        private RateSource $cbr,
        private MarketRateRepository $rates,
        private ExchangeRateProvider $official,
        private CurrencyCatalog $currencies,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int quotes stored; 0 when the source is unreachable (it is retried on the next run)
     */
    public function refresh(DateTimeImmutable $today): int
    {
        try {
            $snapshot = $this->cbr->fetch($today);
        } catch (RateSourceException $exception) {
            $this->logger->warning('CBR rates are unavailable', ['error' => $exception->getMessage()]);

            return 0;
        }

        $codes = array_map(static fn (Currency $currency): string => $currency->code, $this->currencies->foreign());

        return $this->rates->store(RateSourceChoice::Cbr->value, $snapshot, $codes);
    }

    public function rate(RateSourceChoice $source, Currency $currency, DateTimeImmutable $today): ?ExchangeRate
    {
        $base = $this->currencies->base();

        if ($currency->equals($base)) {
            return ExchangeRate::identity($base, $today);
        }

        if ($source === RateSourceChoice::Cba) {
            try {
                return $this->official->rateFor($currency, $today);
            } catch (RateNotFoundException) {
                return null;
            }
        }

        $found = $this->rates->latest($source->value, $currency->code, $today);

        return $found === null ? null : new ExchangeRate($currency, $base, $found['rate'], $found['date']);
    }
}
