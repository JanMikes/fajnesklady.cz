<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterUserCommand
{
    public function __construct(
        #[Assert\NotBlank(message: 'Email is required')]
        #[Assert\Email(message: 'Please provide a valid email address')]
        public string $email,
        #[Assert\NotBlank(message: 'Password is required')]
        #[Assert\Length(
            min: 8,
            minMessage: 'Password must be at least {{ limit }} characters long'
        )]
        #[Assert\PasswordStrength(
            minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
            message: 'Password is too weak. Please use a stronger password.'
        )]
        public string $password,
        #[Assert\NotBlank(message: 'Name is required')]
        #[Assert\Length(
            max: 255,
            maxMessage: 'Name cannot be longer than {{ limit }} characters'
        )]
        public string $name,
    ) {
    }
}
