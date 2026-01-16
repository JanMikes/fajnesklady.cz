<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115140547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE create_place_request (status VARCHAR(30) NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, admin_note TEXT DEFAULT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(500) NOT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(20) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_by_id UUID DEFAULT NULL, created_place_id UUID DEFAULT NULL, requested_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7F2E111C2FFD4FD3 ON create_place_request (processed_by_id)');
        $this->addSql('CREATE INDEX IDX_7F2E111C925F2CAE ON create_place_request (created_place_id)');
        $this->addSql('CREATE INDEX IDX_7F2E111C4DA1E751 ON create_place_request (requested_by_id)');
        $this->addSql('CREATE TABLE place_access (id UUID NOT NULL, granted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, place_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A4A72C3DDA6A219 ON place_access (place_id)');
        $this->addSql('CREATE INDEX IDX_A4A72C3DA76ED395 ON place_access (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A4A72C3DDA6A219A76ED395 ON place_access (place_id, user_id)');
        $this->addSql('CREATE TABLE place_change_request (status VARCHAR(30) NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, admin_note TEXT DEFAULT NULL, id UUID NOT NULL, requested_changes TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_by_id UUID DEFAULT NULL, place_id UUID NOT NULL, requested_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E0961B1A2FFD4FD3 ON place_change_request (processed_by_id)');
        $this->addSql('CREATE INDEX IDX_E0961B1ADA6A219 ON place_change_request (place_id)');
        $this->addSql('CREATE INDEX IDX_E0961B1A4DA1E751 ON place_change_request (requested_by_id)');
        $this->addSql('CREATE TABLE storage_photo (id UUID NOT NULL, path VARCHAR(500) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, storage_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_79634D385CC5DB90 ON storage_photo (storage_id)');
        $this->addSql('ALTER TABLE create_place_request ADD CONSTRAINT FK_7F2E111C2FFD4FD3 FOREIGN KEY (processed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE create_place_request ADD CONSTRAINT FK_7F2E111C925F2CAE FOREIGN KEY (created_place_id) REFERENCES place (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE create_place_request ADD CONSTRAINT FK_7F2E111C4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_access ADD CONSTRAINT FK_A4A72C3DDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_access ADD CONSTRAINT FK_A4A72C3DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_change_request ADD CONSTRAINT FK_E0961B1A2FFD4FD3 FOREIGN KEY (processed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_change_request ADD CONSTRAINT FK_E0961B1ADA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place_change_request ADD CONSTRAINT FK_E0961B1A4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_photo ADD CONSTRAINT FK_79634D385CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place DROP CONSTRAINT fk_741d53cd7e3c61f9');
        $this->addSql('DROP INDEX idx_741d53cd7e3c61f9');
        $this->addSql('ALTER TABLE place DROP owner_id');
        $this->addSql('ALTER TABLE storage ADD price_per_week INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD price_per_month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD owner_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE storage ADD place_id UUID NOT NULL');
        $this->addSql('ALTER TABLE storage ADD CONSTRAINT FK_547A1B347E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage ADD CONSTRAINT FK_547A1B34DA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_547A1B347E3C61F9 ON storage (owner_id)');
        $this->addSql('CREATE INDEX IDX_547A1B34DA6A219 ON storage (place_id)');
        $this->addSql('ALTER TABLE storage_type DROP CONSTRAINT fk_85f39c3cda6a219');
        $this->addSql('DROP INDEX idx_85f39c3cda6a219');
        $this->addSql('ALTER TABLE storage_type ADD default_price_per_week INT NOT NULL');
        $this->addSql('ALTER TABLE storage_type ADD default_price_per_month INT NOT NULL');
        $this->addSql('ALTER TABLE storage_type DROP price_per_week');
        $this->addSql('ALTER TABLE storage_type DROP price_per_month');
        $this->addSql('ALTER TABLE storage_type DROP place_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE create_place_request DROP CONSTRAINT FK_7F2E111C2FFD4FD3');
        $this->addSql('ALTER TABLE create_place_request DROP CONSTRAINT FK_7F2E111C925F2CAE');
        $this->addSql('ALTER TABLE create_place_request DROP CONSTRAINT FK_7F2E111C4DA1E751');
        $this->addSql('ALTER TABLE place_access DROP CONSTRAINT FK_A4A72C3DDA6A219');
        $this->addSql('ALTER TABLE place_access DROP CONSTRAINT FK_A4A72C3DA76ED395');
        $this->addSql('ALTER TABLE place_change_request DROP CONSTRAINT FK_E0961B1A2FFD4FD3');
        $this->addSql('ALTER TABLE place_change_request DROP CONSTRAINT FK_E0961B1ADA6A219');
        $this->addSql('ALTER TABLE place_change_request DROP CONSTRAINT FK_E0961B1A4DA1E751');
        $this->addSql('ALTER TABLE storage_photo DROP CONSTRAINT FK_79634D385CC5DB90');
        $this->addSql('DROP TABLE create_place_request');
        $this->addSql('DROP TABLE place_access');
        $this->addSql('DROP TABLE place_change_request');
        $this->addSql('DROP TABLE storage_photo');
        $this->addSql('ALTER TABLE place ADD owner_id UUID NOT NULL');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT fk_741d53cd7e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_741d53cd7e3c61f9 ON place (owner_id)');
        $this->addSql('ALTER TABLE storage DROP CONSTRAINT FK_547A1B347E3C61F9');
        $this->addSql('ALTER TABLE storage DROP CONSTRAINT FK_547A1B34DA6A219');
        $this->addSql('DROP INDEX IDX_547A1B347E3C61F9');
        $this->addSql('DROP INDEX IDX_547A1B34DA6A219');
        $this->addSql('ALTER TABLE storage DROP price_per_week');
        $this->addSql('ALTER TABLE storage DROP price_per_month');
        $this->addSql('ALTER TABLE storage DROP owner_id');
        $this->addSql('ALTER TABLE storage DROP place_id');
        $this->addSql('ALTER TABLE storage_type ADD price_per_week INT NOT NULL');
        $this->addSql('ALTER TABLE storage_type ADD price_per_month INT NOT NULL');
        $this->addSql('ALTER TABLE storage_type ADD place_id UUID NOT NULL');
        $this->addSql('ALTER TABLE storage_type DROP default_price_per_week');
        $this->addSql('ALTER TABLE storage_type DROP default_price_per_month');
        $this->addSql('ALTER TABLE storage_type ADD CONSTRAINT fk_85f39c3cda6a219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_85f39c3cda6a219 ON storage_type (place_id)');
    }
}
