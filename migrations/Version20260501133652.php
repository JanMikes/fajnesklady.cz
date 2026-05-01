<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501133652 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create email_log table for outgoing email audit trail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_log (
            id UUID NOT NULL,
            attempted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            status VARCHAR(10) NOT NULL,
            error_message TEXT DEFAULT NULL,
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) DEFAULT NULL,
            to_addresses JSONB NOT NULL,
            cc_addresses JSONB DEFAULT NULL,
            bcc_addresses JSONB DEFAULT NULL,
            reply_to_addresses JSONB DEFAULT NULL,
            subject TEXT NOT NULL,
            html_body TEXT DEFAULT NULL,
            text_body TEXT DEFAULT NULL,
            template_name VARCHAR(255) DEFAULT NULL,
            attachments JSONB DEFAULT NULL,
            message_id VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX email_log_attempted_at_idx ON email_log (attempted_at DESC)');
        $this->addSql('CREATE INDEX email_log_status_idx ON email_log (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_log');
    }
}
