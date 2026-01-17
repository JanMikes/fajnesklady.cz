<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260117214815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add self-billing entities: Payment, SelfBillingInvoice, LandlordInvoiceSequence. Add commissionRate to Storage and User, selfBillingPrefix to User.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE landlord_invoice_sequence (last_number INT NOT NULL, id UUID NOT NULL, year INT NOT NULL, landlord_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_525931CDD48E7AED ON landlord_invoice_sequence (landlord_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_525931CDD48E7AEDBB827337 ON landlord_invoice_sequence (landlord_id, year)');
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, amount INT NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, self_billing_invoice_id UUID DEFAULT NULL, order_id UUID DEFAULT NULL, contract_id UUID DEFAULT NULL, storage_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6D28840D2B65D0B8 ON payment (self_billing_invoice_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D8D9F6D38 ON payment (order_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D2576E0FD ON payment (contract_id)');
        $this->addSql('CREATE INDEX IDX_6D28840D5CC5DB90 ON payment (storage_id)');
        $this->addSql('CREATE TABLE self_billing_invoice (pdf_path VARCHAR(500) DEFAULT NULL, fakturoid_invoice_id INT DEFAULT NULL, id UUID NOT NULL, year INT NOT NULL, month INT NOT NULL, invoice_number VARCHAR(20) NOT NULL, gross_amount INT NOT NULL, commission_rate NUMERIC(5, 2) NOT NULL, net_amount INT NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, landlord_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7F70FB8FD48E7AED ON self_billing_invoice (landlord_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7F70FB8FD48E7AEDBB8273378EB61006 ON self_billing_invoice (landlord_id, year, month)');
        $this->addSql('ALTER TABLE landlord_invoice_sequence ADD CONSTRAINT FK_525931CDD48E7AED FOREIGN KEY (landlord_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2B65D0B8 FOREIGN KEY (self_billing_invoice_id) REFERENCES self_billing_invoice (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D5CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE self_billing_invoice ADD CONSTRAINT FK_7F70FB8FD48E7AED FOREIGN KEY (landlord_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage ADD commission_rate NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_type ALTER uniform_storages DROP DEFAULT');
        $this->addSql('ALTER TABLE users ADD commission_rate NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD self_billing_prefix VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9534D67CD ON users (self_billing_prefix)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE landlord_invoice_sequence DROP CONSTRAINT FK_525931CDD48E7AED');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D2B65D0B8');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D8D9F6D38');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D2576E0FD');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D5CC5DB90');
        $this->addSql('ALTER TABLE self_billing_invoice DROP CONSTRAINT FK_7F70FB8FD48E7AED');
        $this->addSql('DROP TABLE landlord_invoice_sequence');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE self_billing_invoice');
        $this->addSql('ALTER TABLE storage DROP commission_rate');
        $this->addSql('ALTER TABLE storage_type ALTER uniform_storages SET DEFAULT true');
        $this->addSql('DROP INDEX UNIQ_1483A5E9534D67CD');
        $this->addSql('ALTER TABLE users DROP commission_rate');
        $this->addSql('ALTER TABLE users DROP self_billing_prefix');
    }
}
