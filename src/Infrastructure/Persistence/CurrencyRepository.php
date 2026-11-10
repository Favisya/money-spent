<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Reference\CurrencyDefinition;
use App\Domain\Money\Currency;
use Doctrine\DBAL\Connection;

final readonly class CurrencyRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<CurrencyDefinition>
     */
    public function findActive(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('code', 'exponent', 'aliases')
            ->from('currency')
            ->where('is_active = TRUE')
            ->orderBy('code')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): CurrencyDefinition => new CurrencyDefinition(
                new Currency(Row::string($row, 'code'), Row::int($row, 'exponent')),
                Row::stringList($row, 'aliases'),
            ),
            $rows,
        );
    }

    /**
     * Raw SQL: Query Builder has no upsert.
     *
     * @param list<string> $aliases
     */
    public function upsert(string $code, int $exponent, array $aliases): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO currency (code, exponent, aliases, is_active)
                VALUES (:code, :exponent, CAST(:aliases AS JSONB), TRUE)
                ON CONFLICT (code) DO UPDATE
                SET exponent = EXCLUDED.exponent, aliases = EXCLUDED.aliases, is_active = TRUE
                SQL,
            [
                'code'     => $code,
                'exponent' => $exponent,
                'aliases'  => json_encode($aliases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );
    }
}
