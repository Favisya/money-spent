<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delivery and service charges of a receipt, paid on top of its items.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE receipt
                ADD COLUMN delivery_minor BIGINT NULL CHECK (delivery_minor >= 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt DROP COLUMN delivery_minor');
    }
}
