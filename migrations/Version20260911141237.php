<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911141237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outbox of GTM dataLayer events confirmed server-side, waiting for a browser to deliver them to.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE analytics_event (pushed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, name VARCHAR(50) NOT NULL, payload JSON NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9CD0310A8D9F6D38 ON analytics_event (order_id)');
        $this->addSql('CREATE INDEX IDX_9CD0310A62C51129 ON analytics_event (pushed_at)');
        $this->addSql('ALTER TABLE analytics_event ADD CONSTRAINT FK_9CD0310A8D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analytics_event DROP CONSTRAINT FK_9CD0310A8D9F6D38');
        $this->addSql('DROP TABLE analytics_event');
    }
}
