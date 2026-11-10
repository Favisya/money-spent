<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Display rates from other sources (Central Bank of Russia), separate from the official CBA rates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE market_rate (
                source       VARCHAR(16)   NOT NULL,
                rate_date    DATE          NOT NULL,
                currency     CHAR(3)       NOT NULL REFERENCES currency (code),
                rate_to_base NUMERIC(18,8) NOT NULL CHECK (rate_to_base > 0),
                fetched_at   TIMESTAMPTZ   NOT NULL DEFAULT now(),
                PRIMARY KEY (source, rate_date, currency)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE market_rate');
    }
}
