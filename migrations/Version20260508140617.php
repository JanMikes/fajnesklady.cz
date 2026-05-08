<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508140617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add overdue_digest_sent table — per-admin per-day idempotency for the daily overdue digest e-mail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE overdue_digest_sent (id UUID NOT NULL, date DATE NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, overdue_count INT NOT NULL, total_amount INT NOT NULL, admin_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1039314C642B8210 ON overdue_digest_sent (admin_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_overdue_digest_admin_date ON overdue_digest_sent (admin_id, date)');
        $this->addSql('ALTER TABLE overdue_digest_sent ADD CONSTRAINT FK_1039314C642B8210 FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE overdue_digest_sent DROP CONSTRAINT FK_1039314C642B8210');
        $this->addSql('DROP TABLE overdue_digest_sent');
    }
}
