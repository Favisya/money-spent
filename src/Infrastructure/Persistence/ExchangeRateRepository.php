<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Money\ExchangeRate;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class ExchangeRateRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Raw SQL: Query Builder has no ON CONFLICT. Stored rates are never overwritten.
     */
    public function insertIfMissing(ExchangeRate $rate, string $source): bool
    {
        return $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO exchange_rate (rate_date, currency, rate_to_base, source)
                VALUES (:rate_date, :currency, :rate, :source)
                ON CONFLICT (rate_date, currency) DO NOTHING
                SQL,
            [
                'rate_date' => $rate->date->format('Y-m-d'),
                'currency'  => $rate->currency->code,
                'rate'      => $rate->rate,
                'source'    => $source,
            ],
        ) === 1;
    }

    /**
     * @return array{date: DateTimeImmutable, rate: string}|null the latest rate on or before the date
     */
    public function latestOnOrBefore(string $currencyCode, DateTimeImmutable $date): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('rate_date', 'rate_to_base')
            ->from('exchange_rate')
            ->where('currency = :currency')
            ->andWhere('rate_date <= :date')
            ->orderBy('rate_date', 'DESC')
            ->setMaxResults(1)
            ->setParameter('currency', $currencyCode)
            ->setParameter('date', $date->format('Y-m-d'))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return ['date' => Row::date($row, 'rate_date'), 'rate' => Row::string($row, 'rate_to_base')];
    }
}
