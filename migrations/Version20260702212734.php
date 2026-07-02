<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702212734 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 077: contract_prolongation audit table + pending card-setup payment id';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contract_prolongation (id UUID NOT NULL, previous_end_date DATE NOT NULL, new_end_date DATE NOT NULL, prolonged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, billing_mode_after VARCHAR(20) NOT NULL, payment_method_after VARCHAR(20) DEFAULT NULL, contract_id UUID NOT NULL, prolonged_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E288CD32576E0FD ON contract_prolongation (contract_id)');
        $this->addSql('CREATE INDEX IDX_E288CD39CBA9103 ON contract_prolongation (prolonged_by_id)');
        $this->addSql('CREATE INDEX idx_contract_prolongation_contract_prolonged ON contract_prolongation (contract_id, prolonged_at)');
        $this->addSql('ALTER TABLE contract_prolongation ADD CONSTRAINT FK_E288CD32576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract_prolongation ADD CONSTRAINT FK_E288CD39CBA9103 FOREIGN KEY (prolonged_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD pending_card_setup_payment_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract_prolongation DROP CONSTRAINT FK_E288CD32576E0FD');
        $this->addSql('ALTER TABLE contract_prolongation DROP CONSTRAINT FK_E288CD39CBA9103');
        $this->addSql('DROP TABLE contract_prolongation');
        $this->addSql('ALTER TABLE contract DROP pending_card_setup_payment_id');
    }
}
