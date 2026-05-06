<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506115924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-place order expiration window (default 3 days)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place ADD order_expiration_days INT NOT NULL DEFAULT 3');
        $this->addSql('ALTER TABLE place ALTER order_expiration_days DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place DROP order_expiration_days');
    }
}
