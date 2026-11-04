<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104145838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record how each first payment, onboarding debt and fine was actually paid (card / transfer / off-system)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fine ADD paid_via VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD paid_via VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD debt_paid_via VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fine DROP paid_via');
        $this->addSql('ALTER TABLE orders DROP paid_via');
        $this->addSql('ALTER TABLE orders DROP debt_paid_via');
    }
}
