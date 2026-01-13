<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260113080940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add billing info fields to users table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD company_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD company_id VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD company_vat_id VARCHAR(14) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD billing_street VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD billing_city VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD billing_postal_code VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP company_name');
        $this->addSql('ALTER TABLE users DROP company_id');
        $this->addSql('ALTER TABLE users DROP company_vat_id');
        $this->addSql('ALTER TABLE users DROP billing_street');
        $this->addSql('ALTER TABLE users DROP billing_city');
        $this->addSql('ALTER TABLE users DROP billing_postal_code');
    }
}
