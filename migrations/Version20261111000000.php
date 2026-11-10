<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Each participant can pick the language the bot talks to them in; empty means their Telegram language.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE participant ADD COLUMN locale VARCHAR(5) NULL
                CHECK (locale IN ('ru', 'en'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participant DROP COLUMN locale');
    }
}
