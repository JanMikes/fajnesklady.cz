<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 076 data migration (runs right before the schema migration that drops
 * rental_type and makes contract.end_date NOT NULL). Cards are recurring-only
 * from now on, so:
 *
 *  1. Defensive endDate fill for any contract the spec-058 backfill missed.
 *  2. Legacy card-MANUAL contracts flip to bank transfer (their per-cycle
 *     one-shot GoPay links no longer exist).
 *  3. Unpaid non-AUTO card orders flip to bank transfer (the one-shot card
 *     payment path is gone; their payment page now renders a QR code).
 *  4. Every order that is (or just became) bank-transfer — plus every order
 *     backing a MANUAL_RECURRING contract — gets a variable symbol so the
 *     reminder e-mails / reconciliation can match payments. The CRC32 scheme
 *     replicates App\Service\Payment\VariableSymbolGenerator exactly.
 */
final class Version20260702160631 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 076: flip legacy one-shot/manual card payments to bank transfer + assign variable symbols';
    }

    public function up(Schema $schema): void
    {
        // 1. Defensive: schema migration right after this one sets NOT NULL.
        $this->addSql(<<<'SQL'
            UPDATE contract
            SET end_date = COALESCE(paid_through_date, next_billing_date, start_date + INTERVAL '1 month')
            WHERE end_date IS NULL
            SQL);

        // 4a. Collect orders that need a variable symbol AFTER the flips below.
        // Reads run now (before the queued UPDATEs), so the predicate mirrors
        // the flip conditions instead of reading their result.
        $usedSymbols = array_column($this->connection->fetchAllAssociative(
            'SELECT variable_symbol FROM orders WHERE variable_symbol IS NOT NULL
             UNION SELECT variable_symbol FROM fine WHERE variable_symbol IS NOT NULL',
        ), 'variable_symbol');
        $used = array_fill_keys(array_map(strval(...), $usedSymbols), true);

        $needingSymbol = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT o.id
            FROM orders o
            LEFT JOIN contract c ON c.order_id = o.id
            WHERE o.variable_symbol IS NULL
              AND (
                o.payment_method = 'bank_transfer'
                OR c.billing_mode = 'manual_recurring'
                OR (o.payment_method = 'gopay' AND o.billing_mode != 'auto_recurring'
                    AND o.status IN ('created', 'reserved', 'awaiting_payment'))
              )
            SQL);

        // 2. Legacy card-MANUAL contracts → bank transfer.
        $this->addSql(<<<'SQL'
            UPDATE orders o
            SET payment_method = 'bank_transfer'
            FROM contract c
            WHERE c.order_id = o.id
              AND c.billing_mode = 'manual_recurring'
              AND o.payment_method = 'gopay'
            SQL);

        // 3. Unpaid non-AUTO card orders → bank transfer.
        $this->addSql(<<<'SQL'
            UPDATE orders
            SET payment_method = 'bank_transfer'
            WHERE payment_method = 'gopay'
              AND billing_mode != 'auto_recurring'
              AND status IN ('created', 'reserved', 'awaiting_payment')
            SQL);

        // 4b. Assign the variable symbols (CRC32 of the order UUID, re-rolled
        // on collision — byte-identical to VariableSymbolGenerator).
        foreach ($needingSymbol as $row) {
            $base = (string) $row['id'];
            $vs = null;
            for ($attempt = 0; $attempt < 10; ++$attempt) {
                $input = 0 === $attempt ? $base : $base.'-'.$attempt;
                $candidate = str_pad((string) abs(crc32($input) % 10_000_000_000), 10, '0', STR_PAD_LEFT);
                if (!isset($used[$candidate])) {
                    $vs = $candidate;

                    break;
                }
            }
            $this->abortIf(null === $vs, sprintf('Cannot generate unique variable symbol for order %s', $base));
            $used[$vs] = true;

            $this->addSql('UPDATE orders SET variable_symbol = :vs WHERE id = :id', ['vs' => $vs, 'id' => $base]);
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible business-data migration: the pre-076 payment methods and
        // the absence of variable symbols cannot be reconstructed. No-op.
    }
}
