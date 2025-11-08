<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251108193704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes on users table for is_verified and created_at columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_users_is_verified ON users(is_verified)');
        $this->addSql('CREATE INDEX idx_users_created_at ON users(created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_users_is_verified');
        $this->addSql('DROP INDEX idx_users_created_at');
    }
}
