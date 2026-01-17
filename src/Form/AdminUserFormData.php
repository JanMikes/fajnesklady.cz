<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Component\Validator\Constraints as Assert;

final class AdminUserFormData
{
    #[Assert\NotBlank(message: 'Zadejte jméno.')]
    #[Assert\Length(max: 100, maxMessage: 'Jméno může mít maximálně {{ limit }} znaků.')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Zadejte příjmení.')]
    #[Assert\Length(max: 100, maxMessage: 'Příjmení může mít maximálně {{ limit }} znaků.')]
    public string $lastName = '';

    #[Assert\Length(max: 20, maxMessage: 'Telefon může mít maximálně {{ limit }} znaků.')]
    public ?string $phone = null;

    #[Assert\Length(max: 255, maxMessage: 'Název firmy může mít maximálně {{ limit }} znaků.')]
    public ?string $companyName = null;

    #[Assert\Length(exactly: 8, exactMessage: 'IČO musí mít přesně {{ limit }} číslic.')]
    #[Assert\Regex(pattern: '/^\d{8}$/', message: 'IČO musí obsahovat pouze číslice.')]
    public ?string $companyId = null;

    #[Assert\Length(max: 14, maxMessage: 'DIČ může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^(CZ\d{8,10})?$/', message: 'DIČ musí být ve formátu CZxxxxxxxx.')]
    public ?string $companyVatId = null;

    #[Assert\Length(max: 255, maxMessage: 'Ulice může mít maximálně {{ limit }} znaků.')]
    public ?string $billingStreet = null;

    #[Assert\Length(max: 100, maxMessage: 'Město může mít maximálně {{ limit }} znaků.')]
    public ?string $billingCity = null;

    #[Assert\Length(max: 10, maxMessage: 'PSČ může mít maximálně {{ limit }} znaků.')]
    public ?string $billingPostalCode = null;

    public UserRole $role = UserRole::USER;

    /** Commission rate as percentage (0-100), e.g., 90 for 90% */
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Provize musi byt mezi 0 a 100%')]
    public ?float $commissionRate = null;

    /** Self-billing invoice prefix for landlords (e.g., P001) */
    #[Assert\Length(max: 10, maxMessage: 'Prefix muze mit maximalne {{ limit }} znaku')]
    #[Assert\Regex(pattern: '/^[A-Z]\d{3}$/', message: 'Prefix musi byt ve formatu P001, P002, ...')]
    public ?string $selfBillingPrefix = null;

    public static function fromUser(User $user): self
    {
        $formData = new self();

        $formData->firstName = $user->firstName;
        $formData->lastName = $user->lastName;
        $formData->phone = $user->phone;

        $formData->companyName = $user->companyName;
        $formData->companyId = $user->companyId;
        $formData->companyVatId = $user->companyVatId;
        $formData->billingStreet = $user->billingStreet;
        $formData->billingCity = $user->billingCity;
        $formData->billingPostalCode = $user->billingPostalCode;

        $roles = $user->getRoles();
        if (in_array(UserRole::ADMIN->value, $roles, true)) {
            $formData->role = UserRole::ADMIN;
        } elseif (in_array(UserRole::LANDLORD->value, $roles, true)) {
            $formData->role = UserRole::LANDLORD;
        } else {
            $formData->role = UserRole::USER;
        }

        // Self-billing settings (for landlords)
        // Cast through float to ensure numeric-string for bcmul
        $formData->commissionRate = null !== $user->commissionRate
            ? (float) bcmul((string) (float) $user->commissionRate, '100', 0)
            : null;
        $formData->selfBillingPrefix = $user->selfBillingPrefix;

        return $formData;
    }
}
