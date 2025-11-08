<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251108181912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE reset_password_request_id_seq CASCADE');
        $this->addSql('ALTER TABLE reset_password_request ALTER id TYPE UUID USING id::text::uuid');
        $this->addSql('ALTER TABLE reset_password_request ALTER id DROP DEFAULT');
        $this->addSql('COMMENT ON COLUMN reset_password_request.id IS \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE reset_password_request_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('ALTER TABLE reset_password_request ALTER id TYPE INT');
        $this->addSql('CREATE SEQUENCE reset_password_request_id_seq');
        $this->addSql('SELECT setval(\'reset_password_request_id_seq\', (SELECT MAX(id) FROM reset_password_request))');
        $this->addSql('ALTER TABLE reset_password_request ALTER id SET DEFAULT nextval(\'reset_password_request_id_seq\')');
        $this->addSql('COMMENT ON COLUMN reset_password_request.id IS NULL');
    }
}
