<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260112223348 extends AbstractMigration
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
        $this->addSql('CREATE TABLE storage (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status VARCHAR(30) NOT NULL, id UUID NOT NULL, number VARCHAR(20) NOT NULL, coordinates JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, storage_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_547A1B34B270BFF1 ON storage (storage_type_id)');
        $this->addSql('CREATE TABLE storage_unavailability (id UUID NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL, reason VARCHAR(500) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, storage_id UUID NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3FEF3BC5CC5DB90 ON storage_unavailability (storage_id)');
        $this->addSql('CREATE INDEX IDX_3FEF3BCB03A8386 ON storage_unavailability (created_by_id)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F28598D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F2859A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contract ADD CONSTRAINT FK_E98F28595CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEEA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE5CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage ADD CONSTRAINT FK_547A1B34B270BFF1 FOREIGN KEY (storage_type_id) REFERENCES storage_type (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_unavailability ADD CONSTRAINT FK_3FEF3BC5CC5DB90 FOREIGN KEY (storage_id) REFERENCES storage (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE storage_unavailability ADD CONSTRAINT FK_3FEF3BCB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE place ADD latitude NUMERIC(10, 7) DEFAULT NULL');
        $this->addSql('ALTER TABLE place ADD longitude NUMERIC(10, 7) DEFAULT NULL');
        $this->addSql('ALTER TABLE place ADD map_image_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE place ADD contract_template_path VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE place ADD days_in_advance INT NOT NULL');
        $this->addSql('ALTER TABLE place ADD is_active BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE place ADD city VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE place ADD postal_code VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE storage_type DROP CONSTRAINT fk_85f39c3c7e3c61f9');
        $this->addSql('DROP INDEX idx_85f39c3c7e3c61f9');
        $this->addSql('ALTER TABLE storage_type ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_type ADD is_active BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE storage_type ALTER width TYPE INT');
        $this->addSql('ALTER TABLE storage_type ALTER height TYPE INT');
        $this->addSql('ALTER TABLE storage_type ALTER length TYPE INT');
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN owner_id TO place_id');
        $this->addSql('ALTER TABLE storage_type ADD CONSTRAINT FK_85F39C3CDA6A219 FOREIGN KEY (place_id) REFERENCES place (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_85F39C3CDA6A219 ON storage_type (place_id)');
        $this->addSql('ALTER TABLE users ADD phone VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD first_name VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE users ADD last_name VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE users DROP name');
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
        $this->addSql('ALTER TABLE storage DROP CONSTRAINT FK_547A1B34B270BFF1');
        $this->addSql('ALTER TABLE storage_unavailability DROP CONSTRAINT FK_3FEF3BC5CC5DB90');
        $this->addSql('ALTER TABLE storage_unavailability DROP CONSTRAINT FK_3FEF3BCB03A8386');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE contract');
        $this->addSql('DROP TABLE orders');
        $this->addSql('DROP TABLE storage');
        $this->addSql('DROP TABLE storage_unavailability');
        $this->addSql('ALTER TABLE place DROP latitude');
        $this->addSql('ALTER TABLE place DROP longitude');
        $this->addSql('ALTER TABLE place DROP map_image_path');
        $this->addSql('ALTER TABLE place DROP contract_template_path');
        $this->addSql('ALTER TABLE place DROP days_in_advance');
        $this->addSql('ALTER TABLE place DROP is_active');
        $this->addSql('ALTER TABLE place DROP city');
        $this->addSql('ALTER TABLE place DROP postal_code');
        $this->addSql('ALTER TABLE storage_type DROP CONSTRAINT FK_85F39C3CDA6A219');
        $this->addSql('DROP INDEX IDX_85F39C3CDA6A219');
        $this->addSql('ALTER TABLE storage_type DROP description');
        $this->addSql('ALTER TABLE storage_type DROP is_active');
        $this->addSql('ALTER TABLE storage_type ALTER width TYPE NUMERIC(10, 2)');
        $this->addSql('ALTER TABLE storage_type ALTER height TYPE NUMERIC(10, 2)');
        $this->addSql('ALTER TABLE storage_type ALTER length TYPE NUMERIC(10, 2)');
        $this->addSql('ALTER TABLE storage_type RENAME COLUMN place_id TO owner_id');
        $this->addSql('ALTER TABLE storage_type ADD CONSTRAINT fk_85f39c3c7e3c61f9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_85f39c3c7e3c61f9 ON storage_type (owner_id)');
        $this->addSql('ALTER TABLE users ADD name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE users DROP phone');
        $this->addSql('ALTER TABLE users DROP first_name');
        $this->addSql('ALTER TABLE users DROP last_name');
    }
}
