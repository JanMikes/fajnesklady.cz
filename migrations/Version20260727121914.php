<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260727121914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'payment.period_start/period_end + backfill the one prepaid-ahead payment';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment ADD period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE payment ADD period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Data backfill (hand-written on purpose): periods of historic payments
        // are not derivable in SQL — except the single payment that had no
        // manual_payment_request row (the 2026-07-27 prepaid-ahead transfer,
        // FIO 27756854538, which settled the cycle 29.08.2026 – 29.09.2026 and
        // therefore rendered as a bare row with no period in the overview).
        $this->addSql(<<<'SQL'
            UPDATE payment
            SET period_start = '2026-08-29 00:00:00',
                period_end = '2026-09-29 00:00:00'
            WHERE bank_transaction_id = (
                SELECT id FROM bank_transaction WHERE fio_transaction_id = '27756854538'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE payment DROP period_start');
        $this->addSql('ALTER TABLE payment DROP period_end');
    }
}
