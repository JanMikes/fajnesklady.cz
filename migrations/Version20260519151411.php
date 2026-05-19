<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260519151411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add onboarding_reminder_sent table — idempotency for app:send-onboarding-payment-reminders';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE onboarding_reminder_sent (id UUID NOT NULL, stage VARCHAR(20) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9A3920F48D9F6D38 ON onboarding_reminder_sent (order_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_onboarding_reminder_order_stage ON onboarding_reminder_sent (order_id, stage)');
        $this->addSql('ALTER TABLE onboarding_reminder_sent ADD CONSTRAINT FK_9A3920F48D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE onboarding_reminder_sent DROP CONSTRAINT FK_9A3920F48D9F6D38');
        $this->addSql('DROP TABLE onboarding_reminder_sent');
    }
}
