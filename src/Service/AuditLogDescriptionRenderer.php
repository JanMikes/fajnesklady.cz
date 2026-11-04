<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Enum\AllocationStepType;
use App\Enum\PaymentMethod;

/**
 * Translates an AuditLog (entityType + eventType + payload) into a human-readable
 * Czech sentence for the admin audit-log Excel export — column "Popis".
 *
 * Mirrors the event taxonomy emitted by {@see AuditLogger}; unknown combinations
 * fall back to "<entityType> — <eventType>" so we never emit raw JSON in the
 * spreadsheet (the previous export shipped JSON which is unreadable in Excel).
 */
final readonly class AuditLogDescriptionRenderer
{
    public function describe(AuditLog $log): string
    {
        $key = $log->entityType.'.'.$log->eventType;

        return match ($key) {
            'order.created' => 'Objednávka vytvořena',
            'order.payment_chosen' => 'Zákazník zvolil způsob platby',
            'order.reserved' => 'Objednávka zarezervovala sklad',
            'order.paid' => $this->withPaymentMethod('Objednávka zaplacena', $log),
            'order.paid_externally' => 'Objednávka označena jako zaplacená mimo systém',
            'order.external_first_payment_recorded' => 'Platba mimo systém zaznamenána administrátorem',
            'contract.external_payment_recorded' => 'Platba mimo systém zaznamenána administrátorem',
            'contract.payment_deadline_extended' => $this->describePaymentDeadlineExtended($log),
            'order.completed' => 'Objednávka dokončena',
            'order.cancelled' => 'Objednávka zrušena',
            'order.signed' => 'Objednávka podepsána',
            'order.expired' => 'Objednávka vypršela',
            'contract.created' => 'Smlouva vytvořena',
            'contract.signed' => 'Smlouva podepsána',
            'contract.terminated' => 'Smlouva ukončena',
            'contract.debt_settled' => 'Dluh uhrazen mimo systém',
            'contract.debt_waived' => 'Dluh odepsán',
            'order.onboarding_debt_settled' => 'Dluh z předchozí smlouvy označen jako uhrazený mimo systém',
            'order.debt_payment_confirmed' => $this->withPaymentMethod('Dluh z předchozí smlouvy uhrazen', $log),
            'contract.recurring_charged' => 'Opakovaná platba stržena z karty',
            'contract.bank_transfer_recurring_confirmed' => 'Opakovaná platba přijata bankovním převodem',
            'order.bank_transfer_payment_confirmed' => 'Platba přijata bankovním převodem',
            'bank_transaction.received' => 'Bankovní převod přijat',
            'contract.credit_converted_to_payment' => 'Přeplatek převeden na platbu a vyfakturován',
            'bank_transaction.allocated' => $this->describeBankTransactionAllocated($log),
            'bank_transaction.manually_paired' => 'Bankovní převod ručně spárován',
            'bank_transaction.auto_matched_to_order' => 'Bankovní převod spárován s objednávkou',
            'bank_transaction.auto_matched_to_contract' => 'Bankovní převod spárován se smlouvou',
            'bank_transaction.matched_via_invoice_number' => 'Bankovní převod spárován podle čísla faktury',
            'bank_transaction.ignored' => 'Bankovní převod ignorován',
            'bank_transaction.credit_allocation_backfilled' => 'Přeplatek z převodu doplněn do kreditu',
            'contract.expiring_soon' => $this->describeContractExpiringSoon($log),
            'storage.reserved' => 'Sklad rezervován',
            'storage.occupied' => 'Sklad obsazen',
            'storage.released' => $this->describeStorageReleased($log),
            'storage.manually_blocked' => $this->describeStorageManuallyBlocked($log),
            'storage.manually_released' => 'Sklad ručně uvolněn',
            'user.password_changed_by_admin' => $this->describeUserPasswordChangedByAdmin($log),
            'manual_payment_request.requested' => $this->describeManualPaymentRequested($log),
            'manual_payment_request.received' => 'Platba na výzvu přijata',
            'order.billing_mode_set' => $this->describeBillingModeSet($log),
            default => sprintf('%s — %s', $log->entityType, $log->eventType),
        };
    }

    private function describeManualPaymentRequested(AuditLog $log): string
    {
        $stage = $log->payload['stage'] ?? null;
        $stageLabel = match ($stage) {
            'initial' => 'úvodní výzva',
            'd_minus_2' => 'připomenutí (2 dny před splatností)',
            'd_zero' => 'splatné dnes',
            'd_plus_3' => 'po splatnosti (3 dny)',
            'd_plus_7' => 'po splatnosti (7 dní)',
            default => is_string($stage) ? $stage : 'neznámá fáze',
        };

        return sprintf('Výzva k platbě převodem — %s', $stageLabel);
    }

    private function describeBillingModeSet(AuditLog $log): string
    {
        $mode = $log->payload['billing_mode'] ?? null;
        $modeLabel = match ($mode) {
            'one_time' => 'jednorázová platba',
            'auto_recurring' => 'automatická opakovaná platba',
            'manual_recurring' => 'opakovaná platba převodem na výzvu',
            default => is_string($mode) ? $mode : 'neznámý režim',
        };

        return sprintf('Způsob platby nastaven: %s', $modeLabel);
    }

    private function withPaymentMethod(string $description, AuditLog $log): string
    {
        $method = $log->payload['payment_method'] ?? null;
        $paymentMethod = is_string($method) ? PaymentMethod::tryFrom($method) : null;

        return null !== $paymentMethod
            ? sprintf('%s (%s)', $description, mb_strtolower($paymentMethod->label()))
            : $description;
    }

    private function describeBankTransactionAllocated(AuditLog $log): string
    {
        $incoming = $log->payload['incoming_amount'] ?? null;
        $steps = $log->payload['steps'] ?? [];

        $parts = [];
        if (is_array($steps)) {
            foreach ($steps as $step) {
                if (!is_array($step) || !is_string($step['type'] ?? null) || !is_int($step['allocated'] ?? null)) {
                    continue;
                }
                $type = AllocationStepType::tryFrom($step['type']);
                $parts[] = sprintf('%s %s Kč', null !== $type ? mb_strtolower($type->label()) : $step['type'], number_format($step['allocated'] / 100, 0, ',', ' '));
            }
        }

        $description = is_int($incoming)
            ? sprintf('Bankovní převod %s Kč rozúčtován', number_format($incoming / 100, 0, ',', ' '))
            : 'Bankovní převod rozúčtován';

        return [] !== $parts ? sprintf('%s: %s', $description, implode(', ', $parts)) : $description;
    }

    private function describePaymentDeadlineExtended(AuditLog $log): string
    {
        $deadline = $log->payload['new_deadline'] ?? null;
        if (is_string($deadline) && '' !== $deadline) {
            return sprintf('Splatnost prodloužena do %s', $deadline);
        }

        return 'Splatnost prodloužena';
    }

    private function describeContractExpiringSoon(AuditLog $log): string
    {
        $days = $log->payload['days_remaining'] ?? null;
        if (is_int($days)) {
            return sprintf('Smlouva brzy vyprší (zbývá %d dní)', $days);
        }

        return 'Smlouva brzy vyprší';
    }

    private function describeStorageReleased(AuditLog $log): string
    {
        $reason = $log->payload['reason'] ?? null;
        if (is_string($reason) && '' !== $reason) {
            return sprintf('Sklad uvolněn: %s', $reason);
        }

        return 'Sklad uvolněn';
    }

    private function describeStorageManuallyBlocked(AuditLog $log): string
    {
        $reason = $log->payload['reason'] ?? null;
        if (is_string($reason) && '' !== $reason) {
            return sprintf('Sklad ručně zablokován: %s', $reason);
        }

        return 'Sklad ručně zablokován';
    }

    private function describeUserPasswordChangedByAdmin(AuditLog $log): string
    {
        $email = $log->payload['target_email'] ?? null;
        if (is_string($email) && '' !== $email) {
            return sprintf('Heslo uživatele změněno administrátorem: %s', $email);
        }

        return 'Heslo uživatele změněno administrátorem';
    }
}
