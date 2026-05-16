<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class GetOrCreateUserByEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * Returns existing user or creates a new passwordless user.
     */
    public function __invoke(GetOrCreateUserByEmailCommand $command): User
    {
        $now = $this->clock->now();
        $existingUser = $this->userRepository->findByEmail($command->email);

        if (null !== $existingUser) {
            $this->syncProfileInfo($existingUser, $command, $now);
            $this->syncBillingInfo($existingUser, $command, $now);
            $this->userRepository->save($existingUser);

            return $existingUser;
        }

        $user = new User(
            id: $this->identityProvider->next(),
            email: $command->email,
            password: null,
            firstName: $command->firstName,
            lastName: $command->lastName,
            createdAt: $now,
        );

        $this->syncProfileInfo($user, $command, $now);
        $this->syncBillingInfo($user, $command, $now);

        // If password provided, hash it and auto-verify the user
        if (null !== $command->plainPassword && '' !== $command->plainPassword) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $command->plainPassword);
            $user->changePassword($hashedPassword, $now);
            $user->markAsVerified($now);
        }

        $this->userRepository->save($user);

        return $user;
    }

    /**
     * Phone and birthDate live on User but are entered on every order form
     * (required for personal-tenant orders, since the contract prints them).
     * When the customer types a value, save it — both for new users and for
     * re-orders by existing users — so a re-ordering customer doesn't end up
     * with a contract showing "Nar. -" / "Telefon: -" because their profile
     * was created passwordless before these fields existed.
     *
     * We never clobber a stored value with null: an empty form field on a
     * re-order keeps the previously stored phone / birthDate intact.
     */
    private function syncProfileInfo(User $user, GetOrCreateUserByEmailCommand $command, \DateTimeImmutable $now): void
    {
        if (null !== $command->phone && '' !== $command->phone) {
            $user->updateProfile($user->firstName, $user->lastName, $command->phone, $now);
        }

        if (null !== $command->birthDate) {
            $user->updateBirthDate($command->birthDate, $now);
        }
    }

    /**
     * Persist address always (it's required on every order), but only overwrite
     * company info when the order explicitly invoices a company — otherwise an
     * existing customer who toggles the company option off would lose their
     * stored IČO/DIČ.
     */
    private function syncBillingInfo(User $user, GetOrCreateUserByEmailCommand $command, \DateTimeImmutable $now): void
    {
        $hasAddress = null !== $command->billingStreet
            && null !== $command->billingCity
            && null !== $command->billingPostalCode;

        if (!$hasAddress) {
            return;
        }

        $isInvoicingCompany = null !== $command->companyId && '' !== $command->companyId;

        $user->updateBillingInfo(
            companyName: $isInvoicingCompany ? $command->companyName : $user->companyName,
            companyId: $isInvoicingCompany ? $command->companyId : $user->companyId,
            companyVatId: $isInvoicingCompany ? $command->companyVatId : $user->companyVatId,
            billingStreet: $command->billingStreet,
            billingCity: $command->billingCity,
            billingPostalCode: $command->billingPostalCode,
            now: $now,
        );
    }
}
