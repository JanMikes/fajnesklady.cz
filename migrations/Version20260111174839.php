<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111174839 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove login rate limiting columns from users table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP failed_login_attempts');
        $this->addSql('ALTER TABLE users DROP locked_until');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD failed_login_attempts INT NOT NULL');
        $this->addSql('ALTER TABLE users ADD locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
}
