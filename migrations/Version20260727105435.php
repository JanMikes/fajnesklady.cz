<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260727105435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'payment.bank_transaction_id + move bank-transaction UUIDs mislabeled as GoPay ids';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment ADD bank_transaction_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DB898B7D6 FOREIGN KEY (bank_transaction_id) REFERENCES bank_transaction (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6D28840DB898B7D6 ON payment (bank_transaction_id)');

        // Data move (hand-written on purpose): bank-transfer reconciliations used
        // to store the bank transaction UUID in go_pay_payment_id, which made the
        // payment overview label them "Kartou (GoPay)". Real GoPay ids are numeric,
        // so anything UUID-shaped that matches an existing bank_transaction row is
        // relocated to the new column.
        $this->addSql(<<<'SQL'
            UPDATE payment
            SET bank_transaction_id = go_pay_payment_id::uuid,
                go_pay_payment_id = NULL
            WHERE go_pay_payment_id ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
              AND EXISTS (
                  SELECT 1 FROM bank_transaction bt
                  WHERE bt.id = payment.go_pay_payment_id::uuid
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reverse of the data move above, so down() does not silently lose the reference.
        $this->addSql(<<<'SQL'
            UPDATE payment
            SET go_pay_payment_id = bank_transaction_id::text
            WHERE bank_transaction_id IS NOT NULL
              AND go_pay_payment_id IS NULL
            SQL);

        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840DB898B7D6');
        $this->addSql('DROP INDEX IDX_6D28840DB898B7D6');
        $this->addSql('ALTER TABLE payment DROP bank_transaction_id');
    }
}
