<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260215180018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage_type ADD place_id UUID NOT NULL');
        $this->addSql('ALTER TABLE storage_type ADD CONSTRAINT FK_85F39C3CDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_85F39C3CDA6A219 ON storage_type (place_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage_type DROP CONSTRAINT FK_85F39C3CDA6A219');
        $this->addSql('DROP INDEX IDX_85F39C3CDA6A219');
        $this->addSql('ALTER TABLE storage_type DROP place_id');
    }
}
