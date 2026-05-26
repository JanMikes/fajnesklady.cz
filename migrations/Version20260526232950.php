<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526232950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fine (variable_symbol VARCHAR(10) DEFAULT NULL, go_pay_payment_id VARCHAR(255) DEFAULT NULL, go_pay_gateway_url VARCHAR(255) DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reminder1_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reminder2_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, type VARCHAR(255) NOT NULL, amount_in_haler INT NOT NULL, description TEXT NOT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cancelled_by_id UUID DEFAULT NULL, contract_id UUID NOT NULL, user_id UUID NOT NULL, issued_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BEA954926377B36E ON fine (variable_symbol)');
        $this->addSql('CREATE INDEX IDX_BEA95492187B2D12 ON fine (cancelled_by_id)');
        $this->addSql('CREATE INDEX IDX_BEA954922576E0FD ON fine (contract_id)');
        $this->addSql('CREATE INDEX IDX_BEA95492A76ED395 ON fine (user_id)');
        $this->addSql('CREATE INDEX IDX_BEA95492784BB717 ON fine (issued_by_id)');
        $this->addSql('ALTER TABLE fine ADD CONSTRAINT FK_BEA95492187B2D12 FOREIGN KEY (cancelled_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fine ADD CONSTRAINT FK_BEA954922576E0FD FOREIGN KEY (contract_id) REFERENCES contract (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fine ADD CONSTRAINT FK_BEA95492A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE fine ADD CONSTRAINT FK_BEA95492784BB717 FOREIGN KEY (issued_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage ADD price_per_month_long_term INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_type ADD default_price_per_month_long_term INT NOT NULL');
        $this->addSql('ALTER TABLE storage_type ALTER default_price_per_year SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fine DROP CONSTRAINT FK_BEA95492187B2D12');
        $this->addSql('ALTER TABLE fine DROP CONSTRAINT FK_BEA954922576E0FD');
        $this->addSql('ALTER TABLE fine DROP CONSTRAINT FK_BEA95492A76ED395');
        $this->addSql('ALTER TABLE fine DROP CONSTRAINT FK_BEA95492784BB717');
        $this->addSql('DROP TABLE fine');
        $this->addSql('ALTER TABLE storage DROP price_per_month_long_term');
        $this->addSql('ALTER TABLE storage_type DROP default_price_per_month_long_term');
        $this->addSql('ALTER TABLE storage_type ALTER default_price_per_year DROP NOT NULL');
    }
}
