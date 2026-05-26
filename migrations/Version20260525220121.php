<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260525220121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bank_account_mapping (id UUID NOT NULL, account_number VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, order_id UUID NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7F550ACEA76ED395 ON bank_account_mapping (user_id)');
        $this->addSql('CREATE INDEX IDX_7F550ACE8D9F6D38 ON bank_account_mapping (order_id)');
        $this->addSql('CREATE INDEX IDX_7F550ACEB03A8386 ON bank_account_mapping (created_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7F550ACEB1A4D1278D9F6D38 ON bank_account_mapping (account_number, order_id)');
        $this->addSql('CREATE TABLE bank_transaction (status VARCHAR(20) DEFAULT \'unmatched\' NOT NULL, paired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, match_method VARCHAR(30) DEFAULT NULL, ignore_reason VARCHAR(500) DEFAULT NULL, id UUID NOT NULL, fio_transaction_id VARCHAR(50) NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, variable_symbol VARCHAR(10) DEFAULT NULL, sender_account_number VARCHAR(50) DEFAULT NULL, sender_name VARCHAR(255) DEFAULT NULL, transaction_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, comment VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, paired_order_id UUID DEFAULT NULL, paired_contract_id UUID DEFAULT NULL, paired_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_50BCB3AE3D93DA4F ON bank_transaction (paired_order_id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AED6FAEACC ON bank_transaction (paired_contract_id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AE4B8D6198 ON bank_transaction (paired_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_50BCB3AEF3E1791 ON bank_transaction (fio_transaction_id)');
        $this->addSql('CREATE TABLE platform_settings (bank_transfer_surcharge_in_haler INT DEFAULT 10000 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE bank_account_mapping ADD CONSTRAINT FK_7F550ACEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_account_mapping ADD CONSTRAINT FK_7F550ACE8D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_account_mapping ADD CONSTRAINT FK_7F550ACEB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE3D93DA4F FOREIGN KEY (paired_order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AED6FAEACC FOREIGN KEY (paired_contract_id) REFERENCES contract (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE4B8D6198 FOREIGN KEY (paired_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD variable_symbol VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E52FFDEE6377B36E ON orders (variable_symbol)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bank_account_mapping DROP CONSTRAINT FK_7F550ACEA76ED395');
        $this->addSql('ALTER TABLE bank_account_mapping DROP CONSTRAINT FK_7F550ACE8D9F6D38');
        $this->addSql('ALTER TABLE bank_account_mapping DROP CONSTRAINT FK_7F550ACEB03A8386');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AE3D93DA4F');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AED6FAEACC');
        $this->addSql('ALTER TABLE bank_transaction DROP CONSTRAINT FK_50BCB3AE4B8D6198');
        $this->addSql('DROP TABLE bank_account_mapping');
        $this->addSql('DROP TABLE bank_transaction');
        $this->addSql('DROP TABLE platform_settings');
        $this->addSql('DROP INDEX UNIQ_E52FFDEE6377B36E');
        $this->addSql('ALTER TABLE orders DROP variable_symbol');
    }
}
