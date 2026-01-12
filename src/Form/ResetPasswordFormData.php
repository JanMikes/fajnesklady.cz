<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordFormData
{
    #[Assert\NotBlank(message: 'Please enter a new password')]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'Your password should be at least {{ limit }} characters',
    )]
    #[Assert\PasswordStrength(
        minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
        message: 'Your password is too weak. Please use a stronger password.',
    )]
    public string $newPassword = '';
}
