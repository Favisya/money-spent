<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\LedgerEntry;
use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Operations shared by expenses and settlements. Identifiers come only from the LedgerTable whitelist.
 */
final readonly class LedgerEntryRepository
{
    private const int PENDING_BATCH = 500;

    public function __construct(
        private Connection $connection,
        private CurrencyCatalog $currencies,
    ) {
    }

    /**
     * @return list<LedgerEntry>
     */
    public function findPendingRates(LedgerTable $table): array
    {
        $rows = $this->entryQuery($table)
            ->where('t.deleted_at IS NULL')
            ->andWhere('t.rate_to_base IS NULL')
            ->orderBy('t.id')
            ->setMaxResults(self::PENDING_BATCH)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): LedgerEntry => $this->hydrate($table, $row), $rows);
    }

    /**
     * @return list<DateTimeImmutable> distinct dates of active entries still waiting for a rate, newest first
     */
    public function pendingDates(int $limit): array
    {
        $dates = [];

        foreach (LedgerTable::cases() as $table) {
            $rows = $this->connection->createQueryBuilder()
                ->select(sprintf('DISTINCT t.%s AS entry_date', $table->dateColumn()))
                ->from($table->value, 't')
                ->where('t.deleted_at IS NULL')
                ->andWhere('t.rate_to_base IS NULL')
                ->orderBy('entry_date', 'DESC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $dates[Row::string($row, 'entry_date')] = Row::date($row, 'entry_date');
            }
        }

        krsort($dates);

        return array_slice(array_values($dates), 0, $limit);
    }

    public function applyRate(LedgerTable $table, int $id, string $rate, int $amountBaseMinor): bool
    {
        return $this->connection->createQueryBuilder()
            ->update($table->value)
            ->set('rate_to_base', ':rate')
            ->set('amount_base_minor', ':base')
            ->where('id = :id')
            ->andWhere('rate_to_base IS NULL')
            ->setParameter('rate', $rate)
            ->setParameter('base', $amountBaseMinor, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement() === 1;
    }

    public function softDelete(LedgerTable $table, int $id): bool
    {
        return $this->connection->createQueryBuilder()
            ->update($table->value)
            ->set('deleted_at', 'now()')
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement() === 1;
    }

    public function latestActiveByAuthor(int $authorId, LedgerTable ...$tables): ?LedgerEntry
    {
        $latest     = null;
        $latestTime = '';

        foreach ($tables as $table) {
            $row = $this->entryQuery($table)
                ->addSelect('t.created_at')
                ->where('t.deleted_at IS NULL')
                ->andWhere(sprintf('t.%s = :author', $table->authorColumn()))
                ->orderBy('t.created_at', 'DESC')
                ->addOrderBy('t.id', 'DESC')
                ->setMaxResults(1)
                ->setParameter('author', $authorId, ParameterType::INTEGER)
                ->executeQuery()
                ->fetchAssociative();

            if ($row === false) {
                continue;
            }

            $createdAt = Row::date($row, 'created_at')->format('Y-m-d H:i:s.u');

            if ($latest === null || $createdAt > $latestTime) {
                $latest     = $this->hydrate($table, $row);
                $latestTime = $createdAt;
            }
        }

        return $latest;
    }

    public function countPending(
        ?LedgerTable $only = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int {
        $count = 0;

        foreach ($only === null ? LedgerTable::cases() : [$only] as $table) {
            $query = $this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($table->value, 't')
                ->where('t.deleted_at IS NULL')
                ->andWhere('t.rate_to_base IS NULL');

            if ($from !== null && $to !== null) {
                $query
                    ->andWhere(sprintf('t.%s >= :from', $table->dateColumn()))
                    ->andWhere(sprintf('t.%s < :to', $table->dateColumn()))
                    ->setParameter('from', $from->format('Y-m-d'))
                    ->setParameter('to', $to->format('Y-m-d'));
            }

            $count += Row::int(['count' => $query->executeQuery()->fetchOne()], 'count');
        }

        return $count;
    }

    private function entryQuery(LedgerTable $table): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                't.id',
                't.amount_minor',
                't.currency',
                't.note',
                sprintf('t.%s AS entry_date', $table->dateColumn()),
            )
            ->from($table->value, 't');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(LedgerTable $table, array $row): LedgerEntry
    {
        return new LedgerEntry(
            $table,
            Row::int($row, 'id'),
            Money::ofMinor(Row::int($row, 'amount_minor'), $this->currencies->get(Row::string($row, 'currency'))),
            Row::date($row, 'entry_date'),
            Row::nullableString($row, 'note'),
        );
    }
}
