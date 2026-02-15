<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260215220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make place address and postal_code nullable for map-only locations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE place ALTER address DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE place SET address = '' WHERE address IS NULL");
        $this->addSql('ALTER TABLE place ALTER address SET NOT NULL');
    }
}
