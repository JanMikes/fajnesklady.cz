<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703094559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 081: invoice.fine_id nullable FK — links a Fakturoid invoice to the paid smluvní pokuta it documents';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice ADD fine_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744E90B2A0C FOREIGN KEY (fine_id) REFERENCES fine (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_90651744E90B2A0C ON invoice (fine_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_90651744E90B2A0C');
        $this->addSql('DROP INDEX IDX_90651744E90B2A0C');
        $this->addSql('ALTER TABLE invoice DROP fine_id');
    }
}
