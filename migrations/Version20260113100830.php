<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260113100830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('COMMENT ON COLUMN storage_type_photo.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.storage_type_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.created_at IS \'\'');
        $this->addSql('ALTER INDEX idx_storage_type_photo_storage_type RENAME TO IDX_8AEC0561B270BFF1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('COMMENT ON COLUMN storage_type_photo.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN storage_type_photo.storage_type_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER INDEX idx_8aec0561b270bff1 RENAME TO idx_storage_type_photo_storage_type');
    }
}
