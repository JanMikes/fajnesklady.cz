<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326081218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create handover_protocol and handover_photo tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE handover_photo (id UUID NOT NULL, path VARCHAR(500) NOT NULL, position INT NOT NULL, uploaded_by VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, handover_protocol_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F3D0F2F7570927FF ON handover_photo (handover_protocol_id)');
        $this->addSql('CREATE TABLE handover_protocol (status VARCHAR(30) NOT NULL, tenant_comment TEXT DEFAULT NULL, tenant_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, landlord_comment TEXT DEFAULT NULL, landlord_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, new_lock_code VARCHAR(50) DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reminders_sent_count INT DEFAULT 0 NOT NULL, last_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, contract_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C0CD01BD2576E0FD ON handover_protocol (contract_id)');
        $this->addSql('ALTER TABLE handover_photo ADD CONSTRAINT FK_F3D0F2F7570927FF FOREIGN KEY (handover_protocol_id) REFERENCES handover_protocol (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE handover_protocol ADD CONSTRAINT FK_C0CD01BD2576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE handover_photo DROP CONSTRAINT FK_F3D0F2F7570927FF');
        $this->addSql('ALTER TABLE handover_protocol DROP CONSTRAINT FK_C0CD01BD2576E0FD');
        $this->addSql('DROP TABLE handover_photo');
        $this->addSql('DROP TABLE handover_protocol');
    }
}
