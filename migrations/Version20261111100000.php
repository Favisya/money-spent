<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A recurring expense without a payer is paid by everyone, in equal shares.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_expense ALTER COLUMN payer_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM recurring_expense WHERE payer_id IS NULL');
        $this->addSql('ALTER TABLE recurring_expense ALTER COLUMN payer_id SET NOT NULL');
    }
}
