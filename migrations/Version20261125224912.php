<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125224912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two-walk billing: rental period pointer on contract + rental period start on manual payment requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract ADD next_period_start DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD period_anchor_day SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE contract ADD charge_month_offset SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE manual_payment_request ADD rental_period_start DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contract DROP next_period_start');
        $this->addSql('ALTER TABLE contract DROP period_anchor_day');
        $this->addSql('ALTER TABLE contract DROP charge_month_offset');
        $this->addSql('ALTER TABLE manual_payment_request DROP rental_period_start');
    }
}
