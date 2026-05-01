<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501180136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing sess_lifetime_idx on sessions (expected by PdoSessionHandler::configureSchema()).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX sess_lifetime_idx');
    }
}
