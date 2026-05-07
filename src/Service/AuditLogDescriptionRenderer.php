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
            default => sprintf('%s — %s', $log->entityType, $log->eventType),
        };
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
