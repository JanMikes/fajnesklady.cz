<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260113102103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id VARCHAR(36) NOT NULL, event_type VARCHAR(50) NOT NULL, payload JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F6E1C0F5A76ED395 ON audit_log (user_id)');
        $this->addSql('CREATE INDEX audit_entity_idx ON audit_log (entity_type, entity_id)');
        $this->addSql('CREATE TABLE contract (document_path VARCHAR(500) DEFAULT NULL, signed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, terminated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, rental_type VARCHAR(20) NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_id UUID NOT NULL, user_id UUID NOT NULL, storage_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E98F28598D9F6D38 ON contract (order_id)');
        $this->addSql('CREATE INDEX IDX_E98F2859A76ED395 ON contract (user_id)');
        $this->addSql('CREATE INDEX IDX_E98F28595CC5DB90 ON contract (storage_id)');
        $this->addSql('CREATE TABLE orders (status VARCHAR(30) NOT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, rental_type VARCHAR(20) NOT NULL, payment_frequency VARCHAR(20) DEFAULT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, total_price INT NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, storage_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E52FFDEEA76ED395 ON orders (user_id)');
        $this->addSql('CREATE INDEX IDX_E52FFDEE5CC5DB90 ON orders (storage_id)');
        $this->addSql('CREATE TABLE place (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, latitude NUMERIC(10, 7) DEFAULT NULL, longitude NUMERIC(10, 7) DEFAULT NULL, map_image_path VARCHAR(500) DEFAULT NULL, contract_template_path VARCHAR(500) DEFAULT NULL, days_in_advance INT NOT NULL, is_active BOOLEAN NOT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, address VARCHAR(500) NOT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(20) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_741D53CD7E3C61F9 ON place (owner_id)');
        $this->addSql('CREATE TABLE reset_password_request (id UUID NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7CE748AA76ED395 ON reset_password_request (user_id)');
        $this->addSql('CREATE TABLE storage (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(30) NOT NULL, id UUID NOT NULL, number VARCHAR(20) NOT NULL, coordinates JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, storage_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_547A1B34B270BFF1 ON storage (storage_type_id)');
        $this->addSql('CREATE TABLE storage_type (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, outer_width INT DEFAULT NULL, outer_height INT DEFAULT NULL, outer_length INT DEFAULT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, inner_width INT NOT NULL, inner_height INT NOT NULL, inner_length INT NOT NULL, price_per_week INT NOT NULL, price_per_month INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, place_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_85F39C3CDA6A219 ON storage_type (place_id)');
        $this->addSql('CREATE TABLE storage_type_photo (id UUID NOT NULL, path VARCHAR(500) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, storage_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8AEC0561B270BFF1 ON storage_type_photo (storage_type_id)');
        $this->addSql('CREATE TABLE storage_unavailability (id UUID NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, reason VARCHAR(500) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, storage_id UUID NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3FEF3BC5CC5DB90 ON storage_unavailability (storage_id)');
        $this->addSql('CREATE INDEX IDX_3FEF3BCB03A8386 ON storage_unavailability (created_by_id)');
        $this->addSql('CREATE TABLE users (roles JSON NOT NULL, is_verified BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, phone VARCHAR(20) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, company_id VARCHAR(8) DEFAULT NULL, company_vat_id VARCHAR(14) DEFAULT NULL, billing_street VARCHAR(255) DEFAULT NULL, billing_city VARCHAR(100) DEFAULT NULL, billing_postal_code VARCHAR(10) DEFAULT NULL, id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) DEFAULT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F28598D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F2859A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F28595CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE5CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place ADD CONSTRAINT FK_741D53CD7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage ADD CONSTRAINT FK_547A1B34B270BFF1 FOREIGN KEY (storage_type_id) REFERENCES storage_type (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_type ADD CONSTRAINT FK_85F39C3CDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_type_photo ADD CONSTRAINT FK_8AEC0561B270BFF1 FOREIGN KEY (storage_type_id) REFERENCES storage_type (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_unavailability ADD CONSTRAINT FK_3FEF3BC5CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_unavailability ADD CONSTRAINT FK_3FEF3BCB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE contract DROP CONSTRAINT FK_E98F28598D9F6D38');
        $this->addSql('ALTER TABLE contract DROP CONSTRAINT FK_E98F2859A76ED395');
        $this->addSql('ALTER TABLE contract DROP CONSTRAINT FK_E98F28595CC5DB90');
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT FK_E52FFDEEA76ED395');
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT FK_E52FFDEE5CC5DB90');
        $this->addSql('ALTER TABLE place DROP CONSTRAINT FK_741D53CD7E3C61F9');
        $this->addSql('ALTER TABLE reset_password_request DROP CONSTRAINT FK_7CE748AA76ED395');
        $this->addSql('ALTER TABLE storage DROP CONSTRAINT FK_547A1B34B270BFF1');
        $this->addSql('ALTER TABLE storage_type DROP CONSTRAINT FK_85F39C3CDA6A219');
        $this->addSql('ALTER TABLE storage_type_photo DROP CONSTRAINT FK_8AEC0561B270BFF1');
        $this->addSql('ALTER TABLE storage_unavailability DROP CONSTRAINT FK_3FEF3BC5CC5DB90');
        $this->addSql('ALTER TABLE storage_unavailability DROP CONSTRAINT FK_3FEF3BCB03A8386');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE contract');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE place');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('DROP TABLE storage');
        $this->addSql('DROP TABLE storage_type');
        $this->addSql('DROP TABLE storage_type_photo');
        $this->addSql('DROP TABLE storage_unavailability');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
