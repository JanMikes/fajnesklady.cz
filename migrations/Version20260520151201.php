<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520151201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ON DELETE CASCADE to handover_protocol.contract — protocols are 1:1 with contracts and must not outlive them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE handover_protocol DROP CONSTRAINT fk_c0cd01bd2576e0fd');
        $this->addSql('ALTER TABLE handover_protocol ADD CONSTRAINT FK_C0CD01BD2576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE handover_protocol DROP CONSTRAINT FK_C0CD01BD2576E0FD');
        $this->addSql('ALTER TABLE handover_protocol ADD CONSTRAINT fk_c0cd01bd2576e0fd FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
