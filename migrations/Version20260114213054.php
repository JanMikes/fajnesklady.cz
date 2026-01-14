<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114213054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GoPay payment tracking fields to Order and Contract entities';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract ADD go_pay_parent_payment_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD next_billing_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD last_billed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD failed_billing_attempts INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE contract ADD last_billing_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD go_pay_payment_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD go_pay_parent_payment_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract DROP go_pay_parent_payment_id');
        $this->addSql('ALTER TABLE contract DROP next_billing_date');
        $this->addSql('ALTER TABLE contract DROP last_billed_at');
        $this->addSql('ALTER TABLE contract DROP failed_billing_attempts');
        $this->addSql('ALTER TABLE contract DROP last_billing_failed_at');
        $this->addSql('ALTER TABLE orders DROP go_pay_payment_id');
        $this->addSql('ALTER TABLE orders DROP go_pay_parent_payment_id');
    }
}
