<?php

declare(strict_types=1);

namespace App\User\Entity;

use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

final class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    private Uuid $id;

    public function __construct(
        private User $user,
        \DateTimeInterface $expiresAt,
        string $selector,
        string $hashedToken,
    ) {
        $this->id = Uuid::v7();
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
