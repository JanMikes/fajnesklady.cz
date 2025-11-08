<?php

declare(strict_types=1);

namespace App\User\Command;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RequestPasswordResetCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
    ) {
    }
}
