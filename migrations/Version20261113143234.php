<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113143234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE payment_refund (status VARCHAR(20) NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failure_reason VARCHAR(500) DEFAULT NULL, id UUID NOT NULL, amount INT NOT NULL, mode VARCHAR(20) NOT NULL, refund_from DATE DEFAULT NULL, period_start DATE DEFAULT NULL, period_end DATE DEFAULT NULL, issue_credit_note BOOLEAN NOT NULL, reason VARCHAR(500) DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, credit_note_id UUID DEFAULT NULL, payment_id UUID NOT NULL, order_id UUID NOT NULL, contract_id UUID DEFAULT NULL, requested_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7E148FCE1C696F7A ON payment_refund (credit_note_id)');
        $this->addSql('CREATE INDEX IDX_7E148FCE4C3A3BB ON payment_refund (payment_id)');
        $this->addSql('CREATE INDEX IDX_7E148FCE8D9F6D38 ON payment_refund (order_id)');
        $this->addSql('CREATE INDEX IDX_7E148FCE2576E0FD ON payment_refund (contract_id)');
        $this->addSql('CREATE INDEX IDX_7E148FCE4DA1E751 ON payment_refund (requested_by_id)');
        $this->addSql('CREATE INDEX idx_payment_refund_status ON payment_refund (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_refund_active_per_payment ON payment_refund (payment_id) WHERE (((status)::text <> \'failed\'::text) AND ((mode)::text <> \'external\'::text))');
        $this->addSql('ALTER TABLE payment_refund ADD CONSTRAINT FK_7E148FCE1C696F7A FOREIGN KEY (credit_note_id) REFERENCES invoice (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment_refund ADD CONSTRAINT FK_7E148FCE4C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment_refund ADD CONSTRAINT FK_7E148FCE8D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment_refund ADD CONSTRAINT FK_7E148FCE2576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment_refund ADD CONSTRAINT FK_7E148FCE4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD correction_of_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744FE3DD8A9 FOREIGN KEY (correction_of_id) REFERENCES invoice (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_90651744FE3DD8A9 ON invoice (correction_of_id)');
        $this->addSql('ALTER TABLE payment ADD refund_of_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2F8525EF FOREIGN KEY (refund_of_id) REFERENCES payment (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6D28840D2F8525EF ON payment (refund_of_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment_refund DROP CONSTRAINT FK_7E148FCE1C696F7A');
        $this->addSql('ALTER TABLE payment_refund DROP CONSTRAINT FK_7E148FCE4C3A3BB');
        $this->addSql('ALTER TABLE payment_refund DROP CONSTRAINT FK_7E148FCE8D9F6D38');
        $this->addSql('ALTER TABLE payment_refund DROP CONSTRAINT FK_7E148FCE2576E0FD');
        $this->addSql('ALTER TABLE payment_refund DROP CONSTRAINT FK_7E148FCE4DA1E751');
        $this->addSql('DROP TABLE payment_refund');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_90651744FE3DD8A9');
        $this->addSql('DROP INDEX IDX_90651744FE3DD8A9');
        $this->addSql('ALTER TABLE invoice DROP correction_of_id');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D2F8525EF');
        $this->addSql('DROP INDEX IDX_6D28840D2F8525EF');
        $this->addSql('ALTER TABLE payment DROP refund_of_id');
    }
}
