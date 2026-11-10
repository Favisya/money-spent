<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The seed only inserts missing rows, so existing currencies and default categories learn English words here.
 * Words already present (or added by the user) are not repeated; the rest of each list stays as it is.
 */
final class Version20261111000100 extends AbstractMigration
{
    private const array CURRENCY_ALIASES = [
        'USD' => ['dollar', 'dollars', 'bucks'],
        'EUR' => ['euro', 'euros'],
        'RUB' => ['ruble', 'rubles', 'rouble', 'roubles'],
        'AMD' => ['dram', 'drams'],
    ];

    private const array CATEGORY_KEYWORDS = [
        'groceries'     => ['grocer', 'supermarket'],
        'delivery'      => ['deliver'],
        'utilities'     => ['utilit', 'electric'],
        'gas'           => ['gas', 'gasoline', 'petrol', 'fuel'],
        'subscriptions' => ['subscript'],
        'transfers'     => ['transfers'],
        'other'         => ['other', 'misc'],
    ];

    public function getDescription(): string
    {
        return 'English currency aliases and category keywords for the expense line.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CURRENCY_ALIASES as $code => $words) {
            $this->append('currency', 'aliases', $code, $words);
        }

        foreach (self::CATEGORY_KEYWORDS as $code => $words) {
            $this->append('category', 'keywords', $code, $words);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CURRENCY_ALIASES as $code => $words) {
            $this->remove('currency', 'aliases', $code, $words);
        }

        foreach (self::CATEGORY_KEYWORDS as $code => $words) {
            $this->remove('category', 'keywords', $code, $words);
        }
    }

    /**
     * Raw SQL: jsonb array operations. "@>" instead of "?", which DBAL would take for a placeholder.
     *
     * @param list<string> $words
     */
    private function append(string $table, string $column, string $code, array $words): void
    {
        $this->addSql(
            sprintf(
                <<<'SQL'
                    UPDATE %1$s
                    SET %2$s = %2$s || COALESCE(
                        (
                            SELECT jsonb_agg(word ORDER BY position)
                            FROM jsonb_array_elements(CAST(:words AS JSONB)) WITH ORDINALITY AS added (word, position)
                            WHERE NOT %1$s.%2$s @> jsonb_build_array(word)
                        ),
                        '[]'::jsonb
                    )
                    WHERE code = :code
                    SQL,
                $table,
                $column,
            ),
            ['words' => json_encode($words, JSON_THROW_ON_ERROR), 'code' => $code],
        );
    }

    /**
     * @param list<string> $words
     */
    private function remove(string $table, string $column, string $code, array $words): void
    {
        $this->addSql(
            sprintf('UPDATE %1$s SET %2$s = %2$s - CAST(:words AS TEXT[]) WHERE code = :code', $table, $column),
            ['words' => '{' . implode(',', $words) . '}', 'code' => $code],
        );
    }
}
