<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordFormData
{
    #[Assert\NotBlank(message: 'Zadejte prosím nové heslo.')]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'Heslo musí mít alespoň {{ limit }} znaků.',
    )]
    public string $newPassword = '';
}
