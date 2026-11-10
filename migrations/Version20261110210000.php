<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Each participant can pick a color for their badge and their personal spending in charts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE participant ADD COLUMN color VARCHAR(16) NULL
                CHECK (color IN ('orange', 'violet', 'blue', 'aqua', 'yellow', 'magenta', 'green', 'red'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participant DROP COLUMN color');
    }
}
