<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260516115949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track when an invoice e-mail has been delivered (bundled or standalone) so the standalone SendInvoiceEmailHandler can skip when the invoice already shipped attached to the rental-activated e-mail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD emailed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP emailed_at');
    }
}
