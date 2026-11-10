<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Participants can exist without Telegram (created from the local web app).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE participant ALTER COLUMN telegram_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM participant WHERE telegram_id IS NULL');
        $this->addSql('ALTER TABLE participant ALTER COLUMN telegram_id SET NOT NULL');
    }
}
