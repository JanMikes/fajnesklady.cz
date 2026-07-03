<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703104404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE handover_protocol ADD tenant_skipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE handover_protocol ADD tenant_skipped_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE handover_protocol ADD CONSTRAINT FK_C0CD01BD30475CD8 FOREIGN KEY (tenant_skipped_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_C0CD01BD30475CD8 ON handover_protocol (tenant_skipped_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE handover_protocol DROP CONSTRAINT FK_C0CD01BD30475CD8');
        $this->addSql('DROP INDEX IDX_C0CD01BD30475CD8');
        $this->addSql('ALTER TABLE handover_protocol DROP tenant_skipped_at');
        $this->addSql('ALTER TABLE handover_protocol DROP tenant_skipped_by_id');
    }
}
