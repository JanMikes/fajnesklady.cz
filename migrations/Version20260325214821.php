<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325214821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user deactivation and admin note fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD deactivation_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD admin_note TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP deactivated_at');
        $this->addSql('ALTER TABLE users DROP deactivation_reason');
        $this->addSql('ALTER TABLE users DROP admin_note');
    }
}
