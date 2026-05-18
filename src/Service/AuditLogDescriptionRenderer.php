<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;

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
            'order.reserved' => 'Objednávka zarezervovala sklad',
            'order.paid' => 'Objednávka zaplacena',
            'order.completed' => 'Objednávka dokončena',
            'order.cancelled' => 'Objednávka zrušena',
            'order.signed' => 'Objednávka podepsána',
            'order.expired' => 'Objednávka vypršela',
            'contract.created' => 'Smlouva vytvořena',
            'contract.signed' => 'Smlouva podepsána',
            'contract.terminated' => 'Smlouva ukončena',
            'contract.expiring_soon' => $this->describeContractExpiringSoon($log),
            'storage.reserved' => 'Sklad rezervován',
            'storage.occupied' => 'Sklad obsazen',
            'storage.released' => $this->describeStorageReleased($log),
            'storage.manually_blocked' => $this->describeStorageManuallyBlocked($log),
            'storage.manually_released' => 'Sklad ručně uvolněn',
            'user.password_changed_by_admin' => $this->describeUserPasswordChangedByAdmin($log),
            'manual_payment_request.requested' => $this->describeManualPaymentRequested($log),
            'manual_payment_request.received' => 'Ručně schvalovaná platba přijata',
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

        return sprintf('Ručně schvalovaná platba — %s', $stageLabel);
    }

    private function describeBillingModeSet(AuditLog $log): string
    {
        $mode = $log->payload['billing_mode'] ?? null;
        $modeLabel = match ($mode) {
            'one_time' => 'jednorázová platba',
            'auto_recurring' => 'automatická opakovaná platba',
            'manual_recurring' => 'ručně schvalovaná opakovaná platba',
            default => is_string($mode) ? $mode : 'neznámý režim',
        };

        return sprintf('Způsob platby nastaven: %s', $modeLabel);
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
