<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508110146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial unique index on payment.go_pay_payment_id (closes parallel-webhook race for recurring charges).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_gopay_payment_id ON payment (go_pay_payment_id) WHERE (go_pay_payment_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_payment_gopay_payment_id');
    }
}
