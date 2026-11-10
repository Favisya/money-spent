<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Rates\RateSnapshot;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class MarketRateRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Stores the quotes of known currencies; a date already stored for the source is left as it is.
     * Raw SQL: Query Builder has no ON CONFLICT.
     *
     * @param list<string> $knownCurrencies
     *
     * @return int quotes stored
     */
    public function store(string $source, RateSnapshot $snapshot, array $knownCurrencies): int
    {
        $stored = 0;

        foreach ($snapshot->quotes as $quote) {
            if (!in_array($quote->currencyCode, $knownCurrencies, true)) {
                continue;
            }

            $stored += $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO market_rate (source, rate_date, currency, rate_to_base)
                    VALUES (:source, :rate_date, :currency, :rate)
                    ON CONFLICT (source, rate_date, currency) DO NOTHING
                    SQL,
                [
                    'source'    => $source,
                    'rate_date' => $snapshot->effectiveDate->format('Y-m-d'),
                    'currency'  => $quote->currencyCode,
                    'rate'      => $quote->rateToBase,
                ],
            );
        }

        return (int) $stored;
    }

    /**
     * @return array{rate: numeric-string, date: DateTimeImmutable}|null
     */
    public function latest(string $source, string $currency, DateTimeImmutable $onOrBefore): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('rate_to_base', 'rate_date')
            ->from('market_rate')
            ->where('source = :source')
            ->andWhere('currency = :currency')
            ->andWhere('rate_date <= :date')
            ->orderBy('rate_date', 'DESC')
            ->setMaxResults(1)
            ->setParameter('source', $source)
            ->setParameter('currency', $currency)
            ->setParameter('date', $onOrBefore->format('Y-m-d'))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $rate = Row::string($row, 'rate_to_base');

        return is_numeric($rate) ? ['rate' => $rate, 'date' => Row::date($row, 'rate_date')] : null;
    }
}
