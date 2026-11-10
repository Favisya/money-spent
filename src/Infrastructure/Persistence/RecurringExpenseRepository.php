<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Recurring\RecurringDraft;
use App\Application\Recurring\RecurringExpense;
use App\Application\Reference\CurrencyCatalog;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class RecurringExpenseRepository
{
    public function __construct(
        private Connection $connection,
        private CurrencyCatalog $currencies,
    ) {
    }

    public function insert(RecurringDraft $draft): int
    {
        $this->connection->createQueryBuilder()
            ->insert('recurring_expense')
            ->values([
                'payer_id'     => ':payer_id',
                'amount_minor' => ':amount_minor',
                'currency'     => ':currency',
                'category_id'  => ':category_id',
                'split'        => ':split',
                'note'         => ':note',
                'day_of_month' => ':day_of_month',
                'next_month'   => ':next_month',
            ])
            ->setParameter('payer_id', $draft->payerId, ParameterType::INTEGER)
            ->setParameter('amount_minor', $draft->amount->minor, ParameterType::INTEGER)
            ->setParameter('currency', $draft->amount->currency->code)
            ->setParameter('category_id', $draft->categoryId, ParameterType::INTEGER)
            ->setParameter('split', $draft->split->value)
            ->setParameter('note', $draft->note)
            ->setParameter('day_of_month', $draft->dayOfMonth, ParameterType::INTEGER)
            ->setParameter('next_month', $draft->nextMonth->format('Y-m-01'))
            ->executeStatement();

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return list<RecurringExpense> active first, then by day of month
     */
    public function findAll(): array
    {
        $rows = $this->viewQuery()
            ->orderBy('r.is_active', 'DESC')
            ->addOrderBy('r.day_of_month')
            ->addOrderBy('r.id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return list<RecurringExpense>
     */
    public function findActive(): array
    {
        $rows = $this->viewQuery()
            ->where('r.is_active = TRUE')
            ->orderBy('r.id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function find(int $id): ?RecurringExpense
    {
        $row = $this->viewQuery()
            ->where('r.id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    public function advance(int $id, DateTimeImmutable $nextMonth): void
    {
        $this->connection->createQueryBuilder()
            ->update('recurring_expense')
            ->set('next_month', ':next_month')
            ->where('id = :id')
            ->setParameter('next_month', $nextMonth->format('Y-m-01'))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->connection->createQueryBuilder()
            ->update('recurring_expense')
            ->set('is_active', ':active')
            ->where('id = :id')
            ->setParameter('active', $active, ParameterType::BOOLEAN)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function delete(int $id): void
    {
        $this->connection->createQueryBuilder()
            ->delete('recurring_expense')
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function linkExpense(int $expenseId, int $recurringId): void
    {
        $this->connection->createQueryBuilder()
            ->update('expense')
            ->set('recurring_id', ':recurring_id')
            ->where('id = :id')
            ->setParameter('recurring_id', $recurringId, ParameterType::INTEGER)
            ->setParameter('id', $expenseId, ParameterType::INTEGER)
            ->executeStatement();
    }

    private function viewQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'r.id',
                'r.payer_id',
                'p.name AS payer_name',
                'r.amount_minor',
                'r.currency',
                'r.category_id',
                'c.code AS category_code',
                'c.title AS category_title',
                'r.split',
                'r.note',
                'r.day_of_month',
                'r.next_month',
                'r.is_active',
            )
            ->from('recurring_expense', 'r')
            ->innerJoin('r', 'participant', 'p', 'p.id = r.payer_id')
            ->innerJoin('r', 'category', 'c', 'c.id = r.category_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RecurringExpense
    {
        return new RecurringExpense(
            Row::int($row, 'id'),
            Row::int($row, 'payer_id'),
            Row::string($row, 'payer_name'),
            Money::ofMinor(Row::int($row, 'amount_minor'), $this->currencies->get(Row::string($row, 'currency'))),
            Row::int($row, 'category_id'),
            Row::string($row, 'category_code'),
            Row::string($row, 'category_title'),
            SplitType::from(Row::string($row, 'split')),
            Row::nullableString($row, 'note'),
            Row::int($row, 'day_of_month'),
            Row::date($row, 'next_month'),
            Row::bool($row, 'is_active'),
        );
    }
}
