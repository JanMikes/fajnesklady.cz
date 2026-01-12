<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260112164108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE place (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_741D53CD7E3C61F9 ON place (owner_id)');
        $this->addSql('CREATE TABLE storage_type (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, width NUMERIC(10, 2) NOT NULL, height NUMERIC(10, 2) NOT NULL, length NUMERIC(10, 2) NOT NULL, price_per_week INT NOT NULL, price_per_month INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_85F39C3C7E3C61F9 ON storage_type (owner_id)');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_type ADD CONSTRAINT FK_85F39C3C7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE places DROP CONSTRAINT fk_feaf6c557e3c61f9');
        $this->addSql('ALTER TABLE storage_types DROP CONSTRAINT fk_34e440107e3c61f9');
        $this->addSql('DROP TABLE places');
        $this->addSql('DROP TABLE storage_types');
        $this->addSql('DROP INDEX selector_idx');
        $this->addSql('DROP INDEX email_idx');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE places (id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX places_owner_idx ON places (owner_id)');
        $this->addSql('CREATE TABLE storage_types (id UUID NOT NULL, name VARCHAR(255) NOT NULL, width NUMERIC(10, 2) NOT NULL, height NUMERIC(10, 2) NOT NULL, length NUMERIC(10, 2) NOT NULL, price_per_week INT NOT NULL, price_per_month INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX storage_types_owner_idx ON storage_types (owner_id)');
        $this->addSql('ALTER TABLE places ADD CONSTRAINT fk_feaf6c557e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE storage_types ADD CONSTRAINT fk_34e440107e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE place DROP CONSTRAINT FK_741D53CD7E3C61F9');
        $this->addSql('ALTER TABLE storage_type DROP CONSTRAINT FK_85F39C3C7E3C61F9');
        $this->addSql('DROP TABLE place');
        $this->addSql('DROP TABLE storage_type');
        $this->addSql('CREATE INDEX selector_idx ON reset_password_request (selector)');
        $this->addSql('CREATE INDEX email_idx ON users (email)');
    }
}
