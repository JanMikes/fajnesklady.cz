<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506220255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-place storage access codes: place columns + place_storage_code_usage table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE place_storage_code_usage (id UUID NOT NULL, code VARCHAR(20) NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, place_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9C0D96D0DA6A219 ON place_storage_code_usage (place_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_place_storage_code_usage_place_code ON place_storage_code_usage (place_id, code)');
        $this->addSql('ALTER TABLE place_storage_code_usage ADD CONSTRAINT FK_9C0D96D0DA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place ADD storage_codes_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE place ADD storage_code_digits INT DEFAULT 4 NOT NULL');
        $this->addSql('ALTER TABLE place ADD storage_code_from INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE place ADD storage_code_to INT DEFAULT 9999 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE place_storage_code_usage DROP CONSTRAINT FK_9C0D96D0DA6A219');
        $this->addSql('DROP TABLE place_storage_code_usage');
        $this->addSql('ALTER TABLE place DROP storage_codes_enabled');
        $this->addSql('ALTER TABLE place DROP storage_code_digits');
        $this->addSql('ALTER TABLE place DROP storage_code_from');
        $this->addSql('ALTER TABLE place DROP storage_code_to');
    }
}
