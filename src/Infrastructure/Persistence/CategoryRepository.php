<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Reference\Category;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class CategoryRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<Category>
     */
    public function findActive(): array
    {
        return array_map(
            self::hydrate(...),
            $this->activeQuery()->executeQuery()->fetchAllAssociative(),
        );
    }

    public function findActiveById(int $id): ?Category
    {
        $row = $this->activeQuery()
            ->andWhere('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<Category> active categories in the order of the given codes
     */
    public function findByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $byCode = [];
        $rows   = $this->activeQuery()
            ->andWhere('code IN (:codes)')
            ->setParameter('codes', $codes, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $category                = self::hydrate($row);
            $byCode[$category->code] = $category;
        }

        return array_values(array_filter(array_map(
            static fn (string $code): ?Category => $byCode[$code] ?? null,
            $codes,
        )));
    }

    /**
     * @return list<Category> active and hidden, in display order
     */
    public function findAll(): array
    {
        return array_map(
            self::hydrate(...),
            $this->baseQuery()->executeQuery()->fetchAllAssociative(),
        );
    }

    public function find(int $id): ?Category
    {
        $row = $this->baseQuery()
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Seeding only adds what is missing: after the first run the user owns titles, keywords and visibility.
     * Raw SQL: Query Builder has no ON CONFLICT.
     *
     * @param list<string> $keywords
     */
    public function insertIfMissing(string $code, string $title, array $keywords, int $sortOrder): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO category (code, title, keywords, is_active, sort_order)
                VALUES (:code, :title, CAST(:keywords AS JSONB), TRUE, :sort_order)
                ON CONFLICT (code) DO NOTHING
                SQL,
            [
                'code'       => $code,
                'title'      => $title,
                'keywords'   => self::json($keywords),
                'sort_order' => $sortOrder,
            ],
        );
    }

    /**
     * @param list<string> $keywords
     */
    public function create(string $code, string $title, array $keywords): int
    {
        $next = (int) $this->connection->createQueryBuilder()
            ->select('COALESCE(MAX(sort_order), 0) + 1')
            ->from('category')
            ->executeQuery()
            ->fetchOne();

        $this->connection->createQueryBuilder()
            ->insert('category')
            ->values([
                'code'       => ':code',
                'title'      => ':title',
                'keywords'   => 'CAST(:keywords AS JSONB)',
                'is_active'  => 'TRUE',
                'sort_order' => ':sort_order',
            ])
            ->setParameter('code', $code)
            ->setParameter('title', $title)
            ->setParameter('keywords', self::json($keywords))
            ->setParameter('sort_order', $next, ParameterType::INTEGER)
            ->executeStatement();

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param list<string> $keywords
     */
    public function update(int $id, string $title, array $keywords): void
    {
        $this->connection->createQueryBuilder()
            ->update('category')
            ->set('title', ':title')
            ->set('keywords', 'CAST(:keywords AS JSONB)')
            ->where('id = :id')
            ->setParameter('title', $title)
            ->setParameter('keywords', self::json($keywords))
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->connection->createQueryBuilder()
            ->update('category')
            ->set('is_active', ':active')
            ->where('id = :id')
            ->setParameter('active', $active, ParameterType::BOOLEAN)
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @return array<int, int> category id => number of active expenses
     */
    public function usage(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('category_id', 'COUNT(*) AS used')
            ->from('expense')
            ->where('deleted_at IS NULL')
            ->groupBy('category_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $usage = [];

        foreach ($rows as $row) {
            $usage[Row::int($row, 'category_id')] = Row::int($row, 'used');
        }

        return $usage;
    }

    private function baseQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('id', 'code', 'title', 'keywords', 'is_active')
            ->from('category')
            ->orderBy('sort_order')
            ->addOrderBy('id');
    }

    /**
     * @param list<string> $keywords
     */
    private static function json(array $keywords): string
    {
        return json_encode($keywords, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function activeQuery(): QueryBuilder
    {
        return $this->baseQuery()->where('is_active = TRUE');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Category
    {
        return new Category(
            Row::int($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'title'),
            Row::stringList($row, 'keywords'),
            Row::bool($row, 'is_active'),
        );
    }
}
