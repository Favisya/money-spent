<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Several photos per receipt, such as screenshots of one long delivery order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE receipt_image (
                receipt_id BIGINT      NOT NULL REFERENCES receipt (id) ON DELETE CASCADE,
                position   SMALLINT    NOT NULL CHECK (position >= 0),
                image      BYTEA       NOT NULL,
                image_type VARCHAR(16) NOT NULL
                    CHECK (image_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/gif')),
                PRIMARY KEY (receipt_id, position)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO receipt_image (receipt_id, position, image, image_type)
            SELECT id, 0, image, image_type FROM receipt WHERE image IS NOT NULL
            SQL);

        $this->addSql('ALTER TABLE receipt DROP COLUMN image, DROP COLUMN image_type');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE receipt
                ADD COLUMN image      BYTEA       NULL,
                ADD COLUMN image_type VARCHAR(16) NULL
                    CHECK (image_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/gif')),
                ADD CONSTRAINT receipt_image_type CHECK ((image IS NULL) = (image_type IS NULL))
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE receipt r
               SET image = i.image, image_type = i.image_type
              FROM receipt_image i
             WHERE i.receipt_id = r.id AND i.position = 0
            SQL);

        $this->addSql('DROP TABLE receipt_image');
    }
}
