<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class SettingRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function get(string $name): ?string
    {
        $value = $this->connection->createQueryBuilder()
            ->select('value')
            ->from('app_setting')
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * Raw SQL: Query Builder has no upsert.
     */
    public function set(string $name, string $value): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO app_setting (name, value) VALUES (:name, :value)
                ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            ['name' => $name, 'value' => $value],
        );
    }

    public function delete(string $name): void
    {
        $this->connection->createQueryBuilder()
            ->delete('app_setting')
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeStatement();
    }
}
