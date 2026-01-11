<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ResetPasswordCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'Reset token is required')]
        public string $token,
        #[Assert\NotBlank(message: 'Password is required')]
        #[Assert\Length(
            min: 8,
            minMessage: 'Password must be at least {{ limit }} characters long'
        )]
        #[Assert\PasswordStrength(
            minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
            message: 'Password is too weak. Please use a stronger password.'
        )]
        public string $newPassword,
    ) {
    }
}
