<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251108194442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account lockout fields (failed_login_attempts, locked_until) to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD failed_login_attempts INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE users ADD locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN users.locked_until IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP failed_login_attempts');
        $this->addSql('ALTER TABLE users DROP locked_until');
    }
}
