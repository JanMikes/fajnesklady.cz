<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260228173039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add electronic signature fields to orders table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD signature_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD signing_method VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD signature_typed_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD signature_style_id VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN orders.signed_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP signature_path');
        $this->addSql('ALTER TABLE orders DROP signing_method');
        $this->addSql('ALTER TABLE orders DROP signature_typed_name');
        $this->addSql('ALTER TABLE orders DROP signature_style_id');
        $this->addSql('ALTER TABLE orders DROP signed_at');
    }
}
