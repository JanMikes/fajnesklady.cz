<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518190731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'spec 036: BillingMode enum on Order/Contract + ManualPaymentRequest table + per-Place + per-Order manual-billing schedule snapshot columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE manual_payment_request (status VARCHAR(20) NOT NULL, go_pay_payment_id VARCHAR(255) DEFAULT NULL, go_pay_gateway_url VARCHAR(1000) DEFAULT NULL, sent_stages JSON DEFAULT \'{}\' NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, amount INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, contract_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C9A3C1472576E0FD ON manual_payment_request (contract_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_manual_payment_request_contract_period ON manual_payment_request (contract_id, period_start)');
        $this->addSql('ALTER TABLE manual_payment_request ADD CONSTRAINT FK_C9A3C1472576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD billing_mode VARCHAR(20) DEFAULT \'auto_recurring\' NOT NULL');
        $this->addSql('ALTER TABLE orders ADD billing_mode VARCHAR(20) DEFAULT \'auto_recurring\' NOT NULL');
        $this->addSql('ALTER TABLE orders ADD manual_billing_offset_initial INT DEFAULT -7 NOT NULL');
        $this->addSql('ALTER TABLE orders ADD manual_billing_offset_reminder INT DEFAULT -2 NOT NULL');
        $this->addSql('ALTER TABLE orders ADD manual_billing_offset_final_due INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE orders ADD manual_billing_offset_overdue_first INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE orders ADD manual_billing_offset_overdue_final INT DEFAULT 7 NOT NULL');
        $this->addSql('ALTER TABLE place ADD manual_billing_offset_initial INT DEFAULT -7 NOT NULL');
        $this->addSql('ALTER TABLE place ADD manual_billing_offset_reminder INT DEFAULT -2 NOT NULL');
        $this->addSql('ALTER TABLE place ADD manual_billing_offset_final_due INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE place ADD manual_billing_offset_overdue_first INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE place ADD manual_billing_offset_overdue_final INT DEFAULT 7 NOT NULL');

        // Backfill: short LIMITED contracts (< 28 days) are one-shots and never
        // re-billed. Tagging them ONE_TIME is hygiene for the recurring-billing
        // predicate (ContractRepository::RECURRING_BILLING_MODES) — otherwise
        // they would be counted as recurring contracts that just happen to have
        // no token, inflating MRR. Everywhere else the auto_recurring default
        // is correct (every pre-existing recurring contract was AUTO).
        $this->addSql(<<<'SQL'
            UPDATE contract SET billing_mode = 'one_time'
            WHERE end_date IS NOT NULL
              AND (end_date - start_date) < 28
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE orders SET billing_mode = 'one_time'
            WHERE end_date IS NOT NULL
              AND (end_date - start_date) < 28
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual_payment_request DROP CONSTRAINT FK_C9A3C1472576E0FD');
        $this->addSql('DROP TABLE manual_payment_request');
        $this->addSql('ALTER TABLE contract DROP billing_mode');
        $this->addSql('ALTER TABLE orders DROP billing_mode');
        $this->addSql('ALTER TABLE orders DROP manual_billing_offset_initial');
        $this->addSql('ALTER TABLE orders DROP manual_billing_offset_reminder');
        $this->addSql('ALTER TABLE orders DROP manual_billing_offset_final_due');
        $this->addSql('ALTER TABLE orders DROP manual_billing_offset_overdue_first');
        $this->addSql('ALTER TABLE orders DROP manual_billing_offset_overdue_final');
        $this->addSql('ALTER TABLE place DROP manual_billing_offset_initial');
        $this->addSql('ALTER TABLE place DROP manual_billing_offset_reminder');
        $this->addSql('ALTER TABLE place DROP manual_billing_offset_final_due');
        $this->addSql('ALTER TABLE place DROP manual_billing_offset_overdue_first');
        $this->addSql('ALTER TABLE place DROP manual_billing_offset_overdue_final');
    }
}
