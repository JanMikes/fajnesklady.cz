<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260215223911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE place_access_request (status VARCHAR(20) NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewed_by_id UUID DEFAULT NULL, place_id UUID NOT NULL, requested_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D8B463A6FC6B21F1 ON place_access_request (reviewed_by_id)');
        $this->addSql('CREATE INDEX IDX_D8B463A6DA6A219 ON place_access_request (place_id)');
        $this->addSql('CREATE INDEX IDX_D8B463A64DA1E751 ON place_access_request (requested_by_id)');
        $this->addSql('ALTER TABLE place_access_request ADD CONSTRAINT FK_D8B463A6FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_access_request ADD CONSTRAINT FK_D8B463A6DA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_access_request ADD CONSTRAINT FK_D8B463A64DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE place_access_request DROP CONSTRAINT FK_D8B463A6FC6B21F1');
        $this->addSql('ALTER TABLE place_access_request DROP CONSTRAINT FK_D8B463A6DA6A219');
        $this->addSql('ALTER TABLE place_access_request DROP CONSTRAINT FK_D8B463A64DA1E751');
        $this->addSql('DROP TABLE place_access_request');
    }
}
