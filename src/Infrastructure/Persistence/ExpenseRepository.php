<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Analytics\SpendingRow;
use App\Application\Expense\ExpenseDraft;
use App\Application\Expense\ExpenseView;
use App\Application\Reference\CurrencyCatalog;
use App\Application\Report\CategoryTotal;
use App\Domain\Balance\LedgerExpense;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Split\SplitType;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use UnexpectedValueException;

final readonly class ExpenseRepository
{
    public function __construct(
        private Connection $connection,
        private CurrencyCatalog $currencies,
    ) {
    }

    /**
     * Raw SQL: Query Builder has no ON CONFLICT / RETURNING. A repeated uuid returns the existing id.
     */
    public function insert(ExpenseDraft $draft): int
    {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO expense (
                    uuid, payer_id, spent_at, amount_minor, currency, rate_to_base,
                    amount_base_minor, category_id, split, note, recurring_id
                )
                VALUES (
                    :uuid, :payer_id, :spent_at, :amount_minor, :currency, :rate_to_base,
                    :amount_base_minor, :category_id, :split, :note, :recurring_id
                )
                ON CONFLICT (uuid) DO NOTHING
                RETURNING id
                SQL,
            [
                'uuid'              => $draft->uuid,
                'payer_id'          => $draft->payerId,
                'spent_at'          => $draft->spentAt->format('Y-m-d'),
                'amount_minor'      => $draft->amount->minor,
                'currency'          => $draft->amount->currency->code,
                'rate_to_base'      => $draft->rate?->rate,
                'amount_base_minor' => $draft->amountBase()?->minor,
                'category_id'       => $draft->categoryId,
                'split'             => $draft->split->value,
                'note'              => $draft->note,
                'recurring_id'      => $draft->recurringId,
            ],
        );

        return $id === false ? $this->idByUuid($draft->uuid) : Row::int(['id' => $id], 'id');
    }

    public function findView(int $id): ?ExpenseView
    {
        $row = $this->viewQuery()
            ->where('e.id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->hydrateView($row);
    }

    /**
     * @return list<ExpenseView> active expenses of the period, oldest first
     */
    public function findViews(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = $this->viewQuery()->where('e.deleted_at IS NULL');
        $rows  = $this->inPeriod($query, $from, $to)
            ->orderBy('e.spent_at')
            ->addOrderBy('e.id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrateView(...), $rows);
    }

    public function updateCategory(int $id, int $categoryId): void
    {
        $this->connection->createQueryBuilder()
            ->update('expense')
            ->set('category_id', ':category_id')
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('category_id', $categoryId, ParameterType::INTEGER)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * Active expenses with a known rate.
     *
     * @return list<LedgerExpense>
     */
    public function ledger(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('e.payer_id', 'e.amount_base_minor', 'e.split')
            ->from('expense', 'e')
            ->where('e.deleted_at IS NULL')
            ->andWhere('e.amount_base_minor IS NOT NULL')
            ->orderBy('e.id');

        if ($from !== null && $to !== null) {
            $this->inPeriod($query, $from, $to);
        }

        return array_map(
            static fn (array $row): LedgerExpense => new LedgerExpense(
                Row::int($row, 'payer_id'),
                Row::int($row, 'amount_base_minor'),
                SplitType::from(Row::string($row, 'split')),
            ),
            $query->executeQuery()->fetchAllAssociative(),
        );
    }

    /**
     * @return list<CategoryTotal> biggest first, only expenses with a known rate
     */
    public function categoryTotals(DateTimeImmutable $from, DateTimeImmutable $to, Currency $base): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select('c.title', 'SUM(e.amount_base_minor) AS total', 'COUNT(e.id) AS cnt')
            ->from('expense', 'e')
            ->innerJoin('e', 'category', 'c', 'c.id = e.category_id')
            ->where('e.deleted_at IS NULL')
            ->andWhere('e.amount_base_minor IS NOT NULL')
            ->groupBy('c.id', 'c.title')
            ->orderBy('total', 'DESC')
            ->addOrderBy('c.title');

        return array_map(
            static fn (array $row): CategoryTotal => new CategoryTotal(
                Row::string($row, 'title'),
                Money::ofMinor(Row::int($row, 'total'), $base),
                Row::int($row, 'cnt'),
            ),
            $this->inPeriod($query, $from, $to)->executeQuery()->fetchAllAssociative(),
        );
    }

    public function existsByUuid(string $uuid): bool
    {
        return $this->connection->createQueryBuilder()
            ->select('1')
            ->from('expense')
            ->where('uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->executeQuery()
            ->fetchOne() !== false;
    }

    /**
     * @return array<string, int> "Y-m" => total in base minor units, only expenses with a known rate
     */
    public function monthlyTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select("TO_CHAR(e.spent_at, 'YYYY-MM') AS month", 'SUM(e.amount_base_minor) AS total')
            ->from('expense', 'e')
            ->where('e.deleted_at IS NULL')
            ->andWhere('e.amount_base_minor IS NOT NULL')
            ->groupBy('month')
            ->orderBy('month');

        $totals = [];

        foreach ($this->inPeriod($query, $from, $to)->executeQuery()->fetchAllAssociative() as $row) {
            $totals[Row::string($row, 'month')] = Row::int($row, 'total');
        }

        return $totals;
    }

    /**
     * Active expenses of the period, including those still waiting for an exchange rate.
     *
     * @return list<SpendingRow>
     */
    public function spendingRows(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $query = $this->connection->createQueryBuilder()
            ->select(
                'e.id',
                'e.payer_id',
                'e.spent_at',
                'e.category_id',
                'e.split',
                'e.amount_minor',
                'e.currency',
                'e.amount_base_minor',
                'e.note',
            )
            ->from('expense', 'e')
            ->where('e.deleted_at IS NULL')
            ->orderBy('e.spent_at')
            ->addOrderBy('e.id');

        return array_map(
            fn (array $row): SpendingRow => new SpendingRow(
                Row::int($row, 'id'),
                Row::int($row, 'payer_id'),
                Row::date($row, 'spent_at'),
                Row::int($row, 'category_id'),
                SplitType::from(Row::string($row, 'split')),
                Money::ofMinor(
                    Row::int($row, 'amount_minor'),
                    $this->currencies->get(Row::string($row, 'currency')),
                ),
                Row::nullableInt($row, 'amount_base_minor'),
                Row::nullableString($row, 'note'),
            ),
            $this->inPeriod($query, $from, $to)->executeQuery()->fetchAllAssociative(),
        );
    }

    public function updateSplit(int $id, SplitType $split): void
    {
        $this->connection->createQueryBuilder()
            ->update('expense')
            ->set('split', ':split')
            ->where('id = :id')
            ->andWhere('deleted_at IS NULL')
            ->setParameter('split', $split->value)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @return string|null "Y-m" of the earliest active expense
     */
    public function firstMonth(): ?string
    {
        $first = $this->connection->createQueryBuilder()
            ->select('MIN(e.spent_at)')
            ->from('expense', 'e')
            ->where('e.deleted_at IS NULL')
            ->executeQuery()
            ->fetchOne();

        return is_string($first) ? substr($first, 0, 7) : null;
    }

    private function idByUuid(string $uuid): int
    {
        $id = $this->connection->createQueryBuilder()
            ->select('id')
            ->from('expense')
            ->where('uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->executeQuery()
            ->fetchOne();

        if ($id === false) {
            throw new UnexpectedValueException(sprintf('Expense %s vanished after a conflicting insert.', $uuid));
        }

        return Row::int(['id' => $id], 'id');
    }

    private function inPeriod(QueryBuilder $query, DateTimeImmutable $from, DateTimeImmutable $to): QueryBuilder
    {
        return $query
            ->andWhere('e.spent_at >= :from')
            ->andWhere('e.spent_at < :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'));
    }

    private function viewQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'e.id',
                'e.payer_id',
                'p.name AS payer_name',
                'e.spent_at',
                'e.amount_minor',
                'e.currency',
                'e.rate_to_base',
                'e.amount_base_minor',
                'e.category_id',
                'c.title AS category_title',
                'e.split',
                'e.note',
                'e.deleted_at',
                'e.recurring_id',
            )
            ->from('expense', 'e')
            ->innerJoin('e', 'participant', 'p', 'p.id = e.payer_id')
            ->innerJoin('e', 'category', 'c', 'c.id = e.category_id');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateView(array $row): ExpenseView
    {
        $baseMinor = Row::nullableInt($row, 'amount_base_minor');

        return new ExpenseView(
            Row::int($row, 'id'),
            Row::int($row, 'payer_id'),
            Row::string($row, 'payer_name'),
            Row::date($row, 'spent_at'),
            Money::ofMinor(Row::int($row, 'amount_minor'), $this->currencies->get(Row::string($row, 'currency'))),
            Row::nullableString($row, 'rate_to_base'),
            $baseMinor === null ? null : Money::ofMinor($baseMinor, $this->currencies->base()),
            Row::int($row, 'category_id'),
            Row::string($row, 'category_title'),
            SplitType::from(Row::string($row, 'split')),
            Row::nullableString($row, 'note'),
            Row::nullableString($row, 'deleted_at') !== null,
            Row::nullableInt($row, 'recurring_id'),
        );
    }
}
