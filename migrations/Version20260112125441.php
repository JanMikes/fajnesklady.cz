<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260112125441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE places (id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX places_owner_idx ON places (owner_id)');
        $this->addSql('CREATE TABLE storage_types (id UUID NOT NULL, name VARCHAR(255) NOT NULL, width NUMERIC(10, 2) NOT NULL, height NUMERIC(10, 2) NOT NULL, length NUMERIC(10, 2) NOT NULL, price_per_week INT NOT NULL, price_per_month INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX storage_types_owner_idx ON storage_types (owner_id)');
        $this->addSql('ALTER TABLE places ADD CONSTRAINT FK_FEAF6C557E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_types ADD CONSTRAINT FK_34E440107E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE places DROP CONSTRAINT FK_FEAF6C557E3C61F9');
        $this->addSql('ALTER TABLE storage_types DROP CONSTRAINT FK_34E440107E3C61F9');
        $this->addSql('DROP TABLE places');
        $this->addSql('DROP TABLE storage_types');
    }
}
