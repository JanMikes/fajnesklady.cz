<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationFormData
{
    #[Assert\NotBlank(message: 'Please enter your email address')]
    #[Assert\Email(message: 'Please enter a valid email address')]
    public string $email = '';

    #[Assert\NotBlank(message: 'Please enter your name')]
    #[Assert\Length(max: 255, maxMessage: 'Your name cannot be longer than {{ limit }} characters')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Please enter a password')]
    #[Assert\Length(min: 8, minMessage: 'Your password should be at least {{ limit }} characters')]
    #[Assert\PasswordStrength(
        minScore: Assert\PasswordStrength::STRENGTH_MEDIUM,
        message: 'Your password is too weak. Please use a stronger password.',
    )]
    public string $password = '';

    #[Assert\IsTrue(message: 'You must agree to the terms and conditions.')]
    public bool $agreeTerms = false;
}
