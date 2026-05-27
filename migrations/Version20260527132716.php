<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527132716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add orderId + userIdContext columns to audit_log with backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD order_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_log ADD user_id_context UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX audit_order_idx ON audit_log (order_id)');
        $this->addSql('CREATE INDEX audit_user_context_idx ON audit_log (user_id_context)');

        // Backfill orderId for order-type entries
        $this->addSql('UPDATE audit_log SET order_id = entity_id::uuid WHERE entity_type = \'order\' AND order_id IS NULL');

        // Backfill orderId for contract-type entries via join
        $this->addSql('UPDATE audit_log al SET order_id = c.order_id FROM contract c WHERE al.entity_id = c.id::text AND al.entity_type = \'contract\' AND al.order_id IS NULL');

        // Backfill userIdContext for order-type entries via join
        $this->addSql('UPDATE audit_log al SET user_id_context = o.user_id FROM orders o WHERE al.entity_id = o.id::text AND al.entity_type = \'order\' AND al.user_id_context IS NULL');

        // Backfill userIdContext for contract-type entries via join
        $this->addSql('UPDATE audit_log al SET user_id_context = c.user_id FROM contract c WHERE al.entity_id = c.id::text AND al.entity_type = \'contract\' AND al.user_id_context IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX audit_order_idx');
        $this->addSql('DROP INDEX audit_user_context_idx');
        $this->addSql('ALTER TABLE audit_log DROP order_id');
        $this->addSql('ALTER TABLE audit_log DROP user_id_context');
    }
}
