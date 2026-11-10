<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Reference\CurrencyCatalog;
use App\Application\Settlement\SettlementDraft;
use App\Application\Settlement\SettlementView;
use App\Domain\Balance\LedgerSettlement;
use App\Domain\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class SettlementRepository
{
    public function __construct(
        private Connection $connection,
        private CurrencyCatalog $currencies,
    ) {
    }

    /**
     * @return list<SettlementView> active settlements of the period, newest first
     */
    public function findViews(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select(
                's.id',
                'f.name AS from_name',
                't.name AS to_name',
                's.amount_minor',
                's.currency',
                's.amount_base_minor',
                's.settled_at',
                's.note',
            )
            ->from('settlement', 's')
            ->innerJoin('s', 'participant', 'f', 'f.id = s.from_id')
            ->innerJoin('s', 'participant', 't', 't.id = s.to_id')
            ->where('s.deleted_at IS NULL')
            ->andWhere('s.settled_at >= :from')
            ->andWhere('s.settled_at < :to')
            ->orderBy('s.settled_at', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(function (array $row): SettlementView {
            $baseMinor = Row::nullableInt($row, 'amount_base_minor');

            return new SettlementView(
                Row::int($row, 'id'),
                Row::string($row, 'from_name'),
                Row::string($row, 'to_name'),
                Money::ofMinor(Row::int($row, 'amount_minor'), $this->currencies->get(Row::string($row, 'currency'))),
                $baseMinor === null ? null : Money::ofMinor($baseMinor, $this->currencies->base()),
                Row::date($row, 'settled_at'),
                Row::nullableString($row, 'note'),
            );
        }, $rows);
    }

    public function existsByUuid(string $uuid): bool
    {
        return $this->connection->createQueryBuilder()
            ->select('1')
            ->from('settlement')
            ->where('uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->executeQuery()
            ->fetchOne() !== false;
    }

    /**
     * Raw SQL: Query Builder has no ON CONFLICT. A repeated uuid is ignored.
     */
    public function insert(SettlementDraft $draft): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO settlement (
                    uuid, from_id, to_id, amount_minor, currency, rate_to_base, amount_base_minor, settled_at, note
                )
                VALUES (
                    :uuid, :from_id, :to_id, :amount_minor, :currency, :rate_to_base, :amount_base_minor,
                    :settled_at, :note
                )
                ON CONFLICT (uuid) DO NOTHING
                SQL,
            [
                'uuid'              => $draft->uuid,
                'from_id'           => $draft->fromId,
                'to_id'             => $draft->toId,
                'amount_minor'      => $draft->amount->minor,
                'currency'          => $draft->amount->currency->code,
                'rate_to_base'      => $draft->rate?->rate,
                'amount_base_minor' => $draft->amountBase()?->minor,
                'settled_at'        => $draft->settledAt->format('Y-m-d'),
                'note'              => $draft->note,
            ],
        );
    }

    /**
     * Active settlements with a known rate.
     *
     * @return list<LedgerSettlement>
     */
    public function ledger(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('from_id', 'to_id', 'amount_base_minor')
            ->from('settlement')
            ->where('deleted_at IS NULL')
            ->andWhere('amount_base_minor IS NOT NULL')
            ->orderBy('id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): LedgerSettlement => new LedgerSettlement(
                Row::int($row, 'from_id'),
                Row::int($row, 'to_id'),
                Row::int($row, 'amount_base_minor'),
            ),
            $rows,
        );
    }
}
