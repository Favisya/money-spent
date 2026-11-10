<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Sharing\Device;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DeviceRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function insert(int $participantId, string $name, string $tokenHash): int
    {
        $this->connection->insert('device', [
            'participant_id' => $participantId,
            'name'           => $name,
            'token_hash'     => $tokenHash,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findActiveByTokenHash(string $tokenHash): ?Device
    {
        $row = $this->query()
            ->andWhere('d.token_hash = :hash')
            ->setParameter('hash', $tokenHash)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::hydrate($row);
    }

    public function find(int $id): ?Device
    {
        $row = $this->query()
            ->andWhere('d.id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @return list<Device>
     */
    public function findActive(): array
    {
        return array_map(
            self::hydrate(...),
            $this->query()->orderBy('d.id')->executeQuery()->fetchAllAssociative(),
        );
    }

    public function touch(int $id, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update('device')
            ->set('last_seen_at', ':now')
            ->where('id = :id')
            ->setParameter('now', $now->format(DATE_ATOM))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function revoke(int $id, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update('device')
            ->set('revoked_at', ':now')
            ->where('id = :id')
            ->andWhere('revoked_at IS NULL')
            ->setParameter('now', $now->format(DATE_ATOM))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    private function query(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'd.id',
                'd.participant_id',
                'p.name AS participant_name',
                'd.name',
                'd.created_at',
                'd.last_seen_at',
            )
            ->from('device', 'd')
            ->innerJoin('d', 'participant', 'p', 'p.id = d.participant_id')
            ->where('d.revoked_at IS NULL');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Device
    {
        $lastSeen = Row::nullableString($row, 'last_seen_at');

        return new Device(
            Row::int($row, 'id'),
            Row::int($row, 'participant_id'),
            Row::string($row, 'participant_name'),
            Row::string($row, 'name'),
            new DateTimeImmutable(Row::string($row, 'created_at')),
            $lastSeen === null ? null : new DateTimeImmutable($lastSeen),
        );
    }
}
