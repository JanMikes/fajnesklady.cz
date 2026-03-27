<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260327123252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders ADD is_admin_created BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD signing_token VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD payment_method VARCHAR(10) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E52FFDEE140DAC30 ON orders (signing_token)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_E52FFDEE140DAC30');
        $this->addSql('ALTER TABLE orders DROP is_admin_created');
        $this->addSql('ALTER TABLE orders DROP signing_token');
        $this->addSql('ALTER TABLE orders DROP payment_method');
    }
}
