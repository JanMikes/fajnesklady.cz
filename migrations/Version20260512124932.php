<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260512124932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Capture signer IP + user-agent at order acceptance for chargeback / GoPay audit evidence.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders ADD signer_ip_address VARCHAR(45) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD signer_user_agent VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders DROP signer_ip_address');
        $this->addSql('ALTER TABLE orders DROP signer_user_agent');
    }
}
