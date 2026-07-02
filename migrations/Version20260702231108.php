<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702231108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 078: platform_settings.overdue_termination_days (VOP čl. XI auto-termination limit, default 7)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE platform_settings ADD overdue_termination_days INT DEFAULT 7 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE platform_settings DROP overdue_termination_days');
    }
}
