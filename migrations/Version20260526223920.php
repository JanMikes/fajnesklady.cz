<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526223920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Four-tier pricing: add long-term monthly, make yearly required';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storage ADD price_per_month_long_term INT DEFAULT NULL');

        $this->addSql('ALTER TABLE storage_type ADD default_price_per_month_long_term INT NOT NULL DEFAULT 0');
        $this->addSql('UPDATE storage_type SET default_price_per_month_long_term = default_price_per_month');
        $this->addSql('ALTER TABLE storage_type ALTER default_price_per_month_long_term DROP DEFAULT');

        $this->addSql('UPDATE storage_type SET default_price_per_year = default_price_per_month * 12 WHERE default_price_per_year IS NULL');
        $this->addSql('ALTER TABLE storage_type ALTER default_price_per_year SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE storage DROP price_per_month_long_term');
        $this->addSql('ALTER TABLE storage_type DROP default_price_per_month_long_term');
        $this->addSql('ALTER TABLE storage_type ALTER default_price_per_year DROP NOT NULL');
    }
}
