<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class VerifyEmailCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public Uuid $userId,
        #[Assert\NotBlank]
        #[Assert\Url]
        public string $signedUrl,
    ) {
    }
}
