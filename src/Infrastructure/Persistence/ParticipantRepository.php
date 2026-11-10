<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Participant\Participant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use UnexpectedValueException;

final readonly class ParticipantRepository
{
    private const int NAME_LENGTH = 64;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Finds the participant by Telegram id or creates one; an existing participant keeps the name chosen earlier.
     * Raw SQL: Query Builder has no upsert / RETURNING.
     */
    public function register(int $telegramId, string $name): Participant
    {
        $name = trim($name) === '' ? (string) $telegramId : trim($name);
        $row  = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO participant (telegram_id, name)
                VALUES (:telegram_id, :name)
                ON CONFLICT (telegram_id) DO UPDATE SET telegram_id = EXCLUDED.telegram_id
                RETURNING id, telegram_id, name
                SQL,
            [
                'telegram_id' => $telegramId,
                'name'        => mb_substr($name, 0, self::NAME_LENGTH),
            ],
        );

        if ($row === false) {
            throw new UnexpectedValueException('Participant upsert returned nothing.');
        }

        return self::hydrate($row);
    }

    public function create(string $name, ?int $telegramId): Participant
    {
        $this->connection->insert('participant', ['name' => $name, 'telegram_id' => $telegramId]);

        return $this->find((int) $this->connection->lastInsertId())
            ?? throw new UnexpectedValueException('Created participant is missing.');
    }

    public function update(int $id, string $name, ?int $telegramId): void
    {
        $this->connection->createQueryBuilder()
            ->update('participant')
            ->set('name', ':name')
            ->set('telegram_id', ':telegram_id')
            ->where('id = :id')
            ->setParameter('name', $name)
            ->setParameter(
                'telegram_id',
                $telegramId,
                $telegramId === null ? ParameterType::NULL : ParameterType::INTEGER,
            )
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function find(int $id): ?Participant
    {
        $row = $this->connection->createQueryBuilder()
            ->select('id', 'telegram_id', 'name')
            ->from('participant')
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::hydrate($row);
    }

    public function isTelegramIdTaken(int $telegramId, ?int $exceptId): bool
    {
        $query = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('participant')
            ->where('telegram_id = :telegram_id')
            ->setParameter('telegram_id', $telegramId, ParameterType::INTEGER);

        if ($exceptId !== null) {
            $query->andWhere('id <> :id')->setParameter('id', $exceptId, ParameterType::INTEGER);
        }

        return $query->executeQuery()->fetchOne() !== false;
    }

    /**
     * @return list<Participant>
     */
    public function all(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('id', 'telegram_id', 'name')
            ->from('participant')
            ->orderBy('id')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Participant
    {
        return new Participant(
            Row::int($row, 'id'),
            Row::nullableInt($row, 'telegram_id'),
            Row::string($row, 'name'),
        );
    }
}
