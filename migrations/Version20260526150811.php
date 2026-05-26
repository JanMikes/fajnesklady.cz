<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526150811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders ADD onboarding_debt_in_haler INT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD debt_paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD debt_go_pay_payment_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders DROP onboarding_debt_in_haler');
        $this->addSql('ALTER TABLE orders DROP debt_paid_at');
        $this->addSql('ALTER TABLE orders DROP debt_go_pay_payment_id');
    }
}
