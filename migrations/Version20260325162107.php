<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325162107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add termination reason and outstanding debt tracking to contracts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract ADD termination_reason VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD outstanding_debt_amount INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract DROP termination_reason');
        $this->addSql('ALTER TABLE contract DROP outstanding_debt_amount');
    }
}
