<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data-only repair (spec 085): AdminOnboardingHandler used to force
 * paymentMethod to EXTERNAL for prepaid/free onboardings while keeping the
 * form-derived billing mode (AUTO_RECURRING when the admin picked GoPay).
 * Such contracts hold no card token, so no cron ever bills them and the
 * overdue sweep would terminate them for payment failure. EXTERNAL can only
 * legitimately derive to MANUAL_RECURRING (BillingMode::derive), so the
 * predicate below is exact.
 */
final class Version20260706095455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flip orphaned external+auto_recurring onboarding orders/contracts to manual_recurring';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE contract SET billing_mode = 'manual_recurring'
            WHERE billing_mode = 'auto_recurring'
              AND go_pay_parent_payment_id IS NULL
              AND order_id IN (SELECT id FROM orders WHERE payment_method = 'external')
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE orders SET billing_mode = 'manual_recurring'
            WHERE payment_method = 'external' AND billing_mode = 'auto_recurring'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Data repair — the broken auto_recurring state is not worth restoring.
    }
}
