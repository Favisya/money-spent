<?php

declare(strict_types=1);

namespace App\Application\Rates;

use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\ExchangeRate;
use App\Infrastructure\Persistence\ExchangeRateRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class RateUpdater
{
    public function __construct(
        private RateSource $source,
        private ExchangeRateRepository $rates,
        private CurrencyCatalog $currencies,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches the given number of days ending with $last, oldest first.
     */
    public function updateDays(DateTimeImmutable $last, int $days): RateUpdateResult
    {
        $stored = [];
        $errors = [];

        for ($offset = max(1, $days) - 1; $offset >= 0; --$offset) {
            $date = $last->modify(sprintf('-%d days', $offset));
            $key  = $date->format('Y-m-d');

            try {
                $stored[$key] = $this->update($date);
            } catch (RateSourceException $exception) {
                $errors[$key] = $exception->getMessage();
                $this->logger->error('Exchange rate fetch failed', [
                    'date'  => $key,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return new RateUpdateResult($stored, $errors);
    }

    /**
     * @return int number of newly stored rates
     *
     * @throws RateSourceException
     */
    public function update(DateTimeImmutable $date): int
    {
        $snapshot = $this->source->fetch($date);
        $quotes   = [];

        foreach ($snapshot->quotes as $quote) {
            $quotes[$quote->currencyCode] = $quote;
        }

        $stored = 0;

        foreach ($this->currencies->foreign() as $currency) {
            $quote = $quotes[$currency->code] ?? null;

            if ($quote === null) {
                $this->logger->warning('Rate source has no quote', [
                    'currency' => $currency->code,
                    'date'     => $snapshot->effectiveDate->format('Y-m-d'),
                ]);

                continue;
            }

            try {
                $rate = new ExchangeRate(
                    $currency,
                    $this->currencies->base(),
                    $quote->rateToBase,
                    $snapshot->effectiveDate,
                );
            } catch (InvalidArgumentException $exception) {
                $this->logger->warning('Rate source returned an invalid rate', [
                    'currency' => $currency->code,
                    'error'    => $exception->getMessage(),
                ]);

                continue;
            }

            $stored += $this->rates->insertIfMissing($rate, $this->source->name()) ? 1 : 0;
        }

        return $stored;
    }
}
