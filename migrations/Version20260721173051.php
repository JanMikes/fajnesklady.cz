<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 091: payment allocation.
 *
 * - `bank_transaction_allocation` records what each part of a transfer was for,
 *   so debt money and first-payment money can never be counted against each
 *   other (the double-count found reviewing spec 089).
 * - `contract.credit_balance` holds money received but not yet consumed by an
 *   obligation; existing rows default to 0.
 *
 * BankTransaction.status became a backed enum with the same values, so it needs
 * no DDL — the column stays varchar(20).
 */
final class Version20260721173051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 091: bank_transaction_allocation table + contract.credit_balance';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bank_transaction_allocation (id UUID NOT NULL, type VARCHAR(30) NOT NULL, amount_in_haler INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, bank_transaction_id UUID NOT NULL, order_id UUID NOT NULL, contract_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2B3B269B898B7D6 ON bank_transaction_allocation (bank_transaction_id)');
        $this->addSql('CREATE INDEX IDX_2B3B2698D9F6D38 ON bank_transaction_allocation (order_id)');
        $this->addSql('CREATE INDEX IDX_2B3B2692576E0FD ON bank_transaction_allocation (contract_id)');
        $this->addSql('CREATE INDEX IDX_2B3B2698D9F6D388CDE5729 ON bank_transaction_allocation (order_id, type)');
        $this->addSql('ALTER TABLE bank_transaction_allocation ADD CONSTRAINT FK_2B3B269B898B7D6 FOREIGN KEY (bank_transaction_id) REFERENCES bank_transaction (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction_allocation ADD CONSTRAINT FK_2B3B2698D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction_allocation ADD CONSTRAINT FK_2B3B2692576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD credit_balance INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bank_transaction_allocation DROP CONSTRAINT FK_2B3B269B898B7D6');
        $this->addSql('ALTER TABLE bank_transaction_allocation DROP CONSTRAINT FK_2B3B2698D9F6D38');
        $this->addSql('ALTER TABLE bank_transaction_allocation DROP CONSTRAINT FK_2B3B2692576E0FD');
        $this->addSql('DROP TABLE bank_transaction_allocation');
        $this->addSql('ALTER TABLE contract DROP credit_balance');
    }
}
