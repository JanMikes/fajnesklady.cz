<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526235159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add paymentDemandSentAt field to contract (spec 055)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract ADD payment_demand_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract DROP payment_demand_sent_at');
    }
}
