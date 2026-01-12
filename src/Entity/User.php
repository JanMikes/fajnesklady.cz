<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @var array<string>
     */
    #[ORM\Column]
    private array $roles;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 180, unique: true)]
        private(set) string $email,
        #[ORM\Column]
        private string $password,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->roles = [UserRole::USER->value];
        $this->updatedAt = $this->createdAt;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        assert('' !== $this->email, 'Email must not be empty');

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function changePassword(string $hashedPassword, \DateTimeImmutable $now): void
    {
        $this->password = $hashedPassword;
        $this->updatedAt = $now;
    }

    /**
     * Returns the roles assigned to the user.
     * ROLE_USER is always included and stored internally, not added dynamically.
     *
     * @return array<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function markAsVerified(\DateTimeImmutable $now): void
    {
        $this->isVerified = true;
        $this->updatedAt = $now;
    }

    public function changeRole(UserRole $role, \DateTimeImmutable $now): void
    {
        $this->roles = [UserRole::USER->value, $role->value];
        $this->updatedAt = $now;
    }
}
