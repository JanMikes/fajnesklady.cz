<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702160632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 076: drop rental_type + expected_duration, contract.end_date NOT NULL (every rental is fixed-term)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract DROP rental_type');
        $this->addSql('ALTER TABLE contract ALTER end_date SET NOT NULL');
        $this->addSql('ALTER TABLE orders DROP rental_type');
        $this->addSql('ALTER TABLE orders DROP expected_duration');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract ADD rental_type VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE contract ALTER end_date DROP NOT NULL');
        $this->addSql('ALTER TABLE orders ADD rental_type VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE orders ADD expected_duration VARCHAR(10) DEFAULT NULL');
    }
}
