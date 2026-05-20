<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520124516 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Yearly pricing tier (spec 045): storage_type.default_price_per_year + storage.price_per_year overrides + contract.payment_frequency mirror column';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract ADD payment_frequency VARCHAR(20) DEFAULT \'monthly\' NOT NULL');
        $this->addSql('ALTER TABLE storage ADD price_per_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_type ADD default_price_per_year INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract DROP payment_frequency');
        $this->addSql('ALTER TABLE storage DROP price_per_year');
        $this->addSql('ALTER TABLE storage_type DROP default_price_per_year');
    }
}
