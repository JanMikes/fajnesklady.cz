<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename storage type dimensions to inner, add outer dimensions, create storage_type_photo table';
    }

    public function up(Schema $schema): void
    {
        // Rename existing dimension columns to inner dimensions
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN width TO inner_width');
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN height TO inner_height');
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN length TO inner_length');

        // Add outer dimension columns (nullable)
        $this->addSql('ALTER TABLE storage_type ADD outer_width INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_type ADD outer_height INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_type ADD outer_length INT DEFAULT NULL');

        // Create storage_type_photo table
        $this->addSql('CREATE TABLE storage_type_photo (id UUID NOT NULL, storage_type_id UUID NOT NULL, path VARCHAR(500) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_storage_type_photo_storage_type ON storage_type_photo (storage_type_id)');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.storage_type_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE storage_type_photo ADD CONSTRAINT FK_storage_type_photo_storage_type FOREIGN KEY (storage_type_id) REFERENCES storage_type (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // Drop storage_type_photo table
        $this->addSql('ALTER TABLE storage_type_photo DROP CONSTRAINT FK_storage_type_photo_storage_type');
        $this->addSql('DROP TABLE storage_type_photo');

        // Remove outer dimension columns
        $this->addSql('ALTER TABLE storage_type DROP outer_width');
        $this->addSql('ALTER TABLE storage_type DROP outer_height');
        $this->addSql('ALTER TABLE storage_type DROP outer_length');

        // Rename inner dimension columns back to original
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN inner_width TO width');
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN inner_height TO height');
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN inner_length TO length');
    }
}
