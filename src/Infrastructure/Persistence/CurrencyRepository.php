<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Reference\CurrencyDefinition;
use App\Domain\Money\Currency;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class CurrencyRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Shown and hidden currencies: a hidden one still formats the entries recorded in it.
     *
     * @return list<CurrencyDefinition>
     */
    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('code', 'exponent', 'aliases', 'is_active')
            ->from('currency')
            ->orderBy('code')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): CurrencyDefinition => new CurrencyDefinition(
                new Currency(Row::string($row, 'code'), Row::int($row, 'exponent')),
                Row::stringList($row, 'aliases'),
                Row::bool($row, 'is_active'),
            ),
            $rows,
        );
    }

    /**
     * Raw SQL: Query Builder has no ON CONFLICT. Seeding keeps what the user changed.
     *
     * @param list<string> $aliases
     */
    public function insertIfMissing(string $code, int $exponent, array $aliases): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO currency (code, exponent, aliases, is_active)
                VALUES (:code, :exponent, CAST(:aliases AS JSONB), TRUE)
                ON CONFLICT (code) DO NOTHING
                SQL,
            [
                'code'     => $code,
                'exponent' => $exponent,
                'aliases'  => self::json($aliases),
            ],
        );
    }

    /**
     * Raw SQL: Query Builder has no ON CONFLICT. Adding a hidden currency shows it again.
     *
     * @param list<string> $aliases
     */
    public function add(string $code, int $exponent, array $aliases): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO currency (code, exponent, aliases, is_active)
                VALUES (:code, :exponent, CAST(:aliases AS JSONB), TRUE)
                ON CONFLICT (code) DO UPDATE SET is_active = TRUE, aliases = EXCLUDED.aliases
                SQL,
            [
                'code'     => $code,
                'exponent' => $exponent,
                'aliases'  => self::json($aliases),
            ],
        );
    }

    public function setActive(string $code, bool $active): void
    {
        $this->connection->createQueryBuilder()
            ->update('currency')
            ->set('is_active', ':active')
            ->where('code = :code')
            ->setParameter('active', $active, ParameterType::BOOLEAN)
            ->setParameter('code', $code)
            ->executeStatement();
    }

    /**
     * @param list<string> $aliases
     */
    public function updateAliases(string $code, array $aliases): void
    {
        $this->connection->createQueryBuilder()
            ->update('currency')
            ->set('aliases', 'CAST(:aliases AS JSONB)')
            ->where('code = :code')
            ->setParameter('aliases', self::json($aliases))
            ->setParameter('code', $code)
            ->executeStatement();
    }

    /**
     * @return array<string, int> currency code => number of active entries recorded in it
     */
    public function usage(): array
    {
        $rows = $this->connection->executeQuery(
            <<<'SQL'
                SELECT currency, COUNT(*) AS entries FROM (
                    SELECT currency FROM expense WHERE deleted_at IS NULL
                    UNION ALL
                    SELECT currency FROM settlement WHERE deleted_at IS NULL
                ) AS entry
                GROUP BY currency
                SQL,
        )->fetchAllAssociative();

        $usage = [];

        foreach ($rows as $row) {
            $usage[Row::string($row, 'currency')] = Row::int($row, 'entries');
        }

        return $usage;
    }

    /**
     * @param list<string> $aliases
     */
    private static function json(array $aliases): string
    {
        return json_encode($aliases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
