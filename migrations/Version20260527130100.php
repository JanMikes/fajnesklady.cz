<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527130100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill endDate for existing UNLIMITED contracts (VOP §IV: always doba určitá)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE contract
            SET end_date = COALESCE(paid_through_date, next_billing_date, start_date + INTERVAL '1 month')
            WHERE end_date IS NULL
              AND rental_type = 'unlimited'
              AND payment_frequency = 'monthly'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE contract
            SET end_date = COALESCE(paid_through_date, next_billing_date, start_date + INTERVAL '1 year')
            WHERE end_date IS NULL
              AND rental_type = 'unlimited'
              AND payment_frequency = 'yearly'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE contract
            SET end_date = NULL
            WHERE rental_type = 'unlimited'
            SQL);
    }
}
