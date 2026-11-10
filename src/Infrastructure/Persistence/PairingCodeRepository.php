<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class PairingCodeRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Only one code is valid at a time: issuing a new one retires the previous ones.
     */
    public function replace(
        int $participantId,
        string $codeHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): void {
        $this->connection->transactional(function () use ($participantId, $codeHash, $expiresAt, $now): void {
            $this->connection->createQueryBuilder()
                ->update('pairing_code')
                ->set('used_at', ':now')
                ->where('used_at IS NULL')
                ->setParameter('now', $now->format(DATE_ATOM))
                ->executeStatement();

            $this->connection->insert('pairing_code', [
                'participant_id' => $participantId,
                'code_hash'      => $codeHash,
                'expires_at'     => $expiresAt->format(DATE_ATOM),
            ]);
        });
    }

    /**
     * @return array{id: int, participant_id: int, code_hash: string}|null
     */
    public function findActive(DateTimeImmutable $now, int $maxAttempts): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'participant_id', 'code_hash')
            ->from('pairing_code')
            ->where('used_at IS NULL')
            ->andWhere('expires_at > :now')
            ->andWhere('attempts < :max')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('now', $now->format(DATE_ATOM))
            ->setParameter('max', $maxAttempts, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return [
            'id'             => Row::int($row, 'id'),
            'participant_id' => Row::int($row, 'participant_id'),
            'code_hash'      => Row::string($row, 'code_hash'),
        ];
    }

    public function countFailure(int $id): void
    {
        $this->connection->createQueryBuilder()
            ->update('pairing_code')
            ->set('attempts', 'attempts + 1')
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function markUsed(int $id, DateTimeImmutable $now): void
    {
        $this->connection->createQueryBuilder()
            ->update('pairing_code')
            ->set('used_at', ':now')
            ->where('id = :id')
            ->setParameter('now', $now->format(DATE_ATOM))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }
}
