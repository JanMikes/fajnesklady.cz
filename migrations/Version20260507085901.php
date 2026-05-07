<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260507085901 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 025: onboarding billing controls — Contract.individualMonthlyAmount + Order.individualMonthlyAmount + Order.paidThroughDate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract ADD individual_monthly_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD individual_monthly_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD paid_through_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract DROP individual_monthly_amount');
        $this->addSql('ALTER TABLE orders DROP individual_monthly_amount');
        $this->addSql('ALTER TABLE orders DROP paid_through_date');
    }
}
