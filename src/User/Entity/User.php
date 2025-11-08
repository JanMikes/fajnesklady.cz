<?php

declare(strict_types=1);

namespace App\User\Entity;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    private Uuid $id;
    private string $email;
    private string $password;
    private string $name;
    /** @var array<string> */
    private array $roles = [];
    private bool $isVerified = false;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Uuid $id,
        string $email,
        string $password,
        string $name,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->password = $password;
        $this->name = $name;
        $this->roles = ['ROLE_USER'];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function create(string $email, string $name, string $password): self
    {
        return new self(
            id: Uuid::v7(),
            email: $email,
            password: $password,
            name: $name,
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
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

    public function setPassword(string $password): void
    {
        $this->password = $password;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function markAsVerified(): void
    {
        $this->isVerified = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function changeRole(string $role): void
    {
        $this->roles = [$role];
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
