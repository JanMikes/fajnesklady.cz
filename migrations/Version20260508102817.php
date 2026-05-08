<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508102817 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending_recurring_payment_id to track in-flight GoPay recurring charges (polling-timeout reconciliation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract ADD pending_recurring_payment_id VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract DROP pending_recurring_payment_id');
    }
}
