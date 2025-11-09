<?php

declare(strict_types=1);

namespace App\User\Entity;

use App\User\Enum\UserRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'email_idx', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator')]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 255)]
    private string $name;

    /**
     * @var array<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(name: 'is_verified')]
    private bool $isVerified = false;

    #[ORM\Column(name: 'failed_login_attempts')]
    private int $failedLoginAttempts = 0;

    #[ORM\Column(name: 'locked_until', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
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
        $this->roles = [UserRole::USER->value];
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

    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function recordFailedLoginAttempt(): void
    {
        ++$this->failedLoginAttempts;
        $this->updatedAt = new \DateTimeImmutable();

        // Lock account after 5 failed attempts for 15 minutes
        if ($this->failedLoginAttempts >= 5) {
            $this->lockedUntil = new \DateTimeImmutable('+15 minutes');
        }
    }

    public function resetFailedLoginAttempts(): void
    {
        $this->failedLoginAttempts = 0;
        $this->lockedUntil = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isLocked(): bool
    {
        if (null === $this->lockedUntil) {
            return false;
        }

        // If lock has expired, reset it
        if ($this->lockedUntil < new \DateTimeImmutable()) {
            $this->resetFailedLoginAttempts();

            return false;
        }

        return true;
    }

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }
}
