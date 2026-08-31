<?php

declare(strict_types=1);

namespace App\Service\Customer;

/**
 * The one place that decides which identity a customer is shown under.
 *
 * When the customer supplied company details, the company — not the person —
 * is the counterparty on the contract and the invoice, so the company name
 * takes precedence everywhere a customer is listed. The personal name is kept
 * as a secondary line so an admin still sees who actually signed.
 *
 * Static because both the {@see \App\Entity\User} entity hooks and the raw-SQL
 * admin list row need the identical rule, and neither can inject a service.
 */
final class CustomerDisplayName
{
    public static function isCompany(?string $companyName): bool
    {
        return null !== $companyName && '' !== trim($companyName);
    }

    public static function resolve(string $fullName, ?string $companyName): string
    {
        return self::isCompany($companyName) ? trim((string) $companyName) : $fullName;
    }
}
