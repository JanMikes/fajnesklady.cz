<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508142737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Append-only contract_price_change audit table + Order.created_by_admin FK; backfill one row per contract that already carries an individualMonthlyAmount.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contract_price_change (id UUID NOT NULL, previous_amount INT DEFAULT NULL, new_amount INT DEFAULT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reason TEXT DEFAULT NULL, contract_id UUID NOT NULL, changed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E85943A42576E0FD ON contract_price_change (contract_id)');
        $this->addSql('CREATE INDEX IDX_E85943A4828AD0A0 ON contract_price_change (changed_by_id)');
        $this->addSql('CREATE INDEX idx_contract_price_change_contract_changed ON contract_price_change (contract_id, changed_at)');
        $this->addSql('ALTER TABLE contract_price_change ADD CONSTRAINT FK_E85943A42576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract_price_change ADD CONSTRAINT FK_E85943A4828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD created_by_admin_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE64F1F4EE FOREIGN KEY (created_by_admin_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_E52FFDEE64F1F4EE ON orders (created_by_admin_id)');

        // Backfill: every contract that already carries an individualMonthlyAmount
        // gets one history row. previousAmount=NULL — we have no record of the
        // prior state; the reason string makes the provenance explicit.
        $this->addSql(<<<'SQL'
            INSERT INTO contract_price_change
                (id, contract_id, previous_amount, new_amount, changed_at, changed_by_id, reason)
            SELECT
                gen_random_uuid(),
                c.id,
                NULL,
                c.individual_monthly_amount,
                c.created_at,
                NULL,
                'Initial value (backfill)'
            FROM contract c
            WHERE c.individual_monthly_amount IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contract_price_change DROP CONSTRAINT FK_E85943A42576E0FD');
        $this->addSql('ALTER TABLE contract_price_change DROP CONSTRAINT FK_E85943A4828AD0A0');
        $this->addSql('DROP TABLE contract_price_change');
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT FK_E52FFDEE64F1F4EE');
        $this->addSql('DROP INDEX IDX_E52FFDEE64F1F4EE');
        $this->addSql('ALTER TABLE orders DROP created_by_admin_id');
    }
}
