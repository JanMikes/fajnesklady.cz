<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class ProfileFormData
{
    #[Assert\NotBlank(message: 'Zadejte jméno.')]
    #[Assert\Length(max: 100, maxMessage: 'Jméno může mít maximálně {{ limit }} znaků.')]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Zadejte příjmení.')]
    #[Assert\Length(max: 100, maxMessage: 'Příjmení může mít maximálně {{ limit }} znaků.')]
    public string $lastName = '';

    #[Assert\Length(max: 20, maxMessage: 'Telefon může mít maximálně {{ limit }} znaků.')]
    public ?string $phone = null;

    #[Assert\Length(max: 17, maxMessage: 'Číslo účtu může mít maximálně {{ limit }} znaků.')]
    #[Assert\Regex(pattern: '/^(\d{1,6}-)?\d{1,10}$/', message: 'Zadejte platné číslo účtu.')]
    public ?string $bankAccountNumber = null;

    #[Assert\Length(exactly: 4, exactMessage: 'Kód banky musí mít přesně {{ limit }} znaky.')]
    #[Assert\Regex(pattern: '/^\d{4}$/', message: 'Kód banky musí obsahovat 4 číslice.')]
    public ?string $bankCode = null;

    public static function fromUser(User $user): self
    {
        $formData = new self();
        $formData->firstName = $user->firstName;
        $formData->lastName = $user->lastName;
        $formData->phone = $user->phone;
        $formData->bankAccountNumber = $user->bankAccountNumber;
        $formData->bankCode = $user->bankCode;

        return $formData;
    }
}
