<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325152906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add termination notice, paid-through tracking, and GoPay payment ID for recurring payments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract ADD termination_noticed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD terminates_at DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD paid_through_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD go_pay_payment_id VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract DROP termination_noticed_at');
        $this->addSql('ALTER TABLE contract DROP terminates_at');
        $this->addSql('ALTER TABLE contract DROP paid_through_date');
        $this->addSql('ALTER TABLE payment DROP go_pay_payment_id');
    }
}
