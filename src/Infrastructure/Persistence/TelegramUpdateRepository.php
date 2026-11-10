<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class TelegramUpdateRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Raw SQL: Query Builder has no ON CONFLICT.
     *
     * @return bool false when the update was already processed
     */
    public function markProcessed(int $updateId): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO telegram_update (update_id) VALUES (:id) ON CONFLICT (update_id) DO NOTHING',
            ['id' => $updateId],
        ) === 1;
    }
}
