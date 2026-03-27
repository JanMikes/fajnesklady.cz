<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Event\AdminOnboardingInitiated;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdminCreateOnboardingHandler
{
    private const int ONBOARDING_EXPIRATION_DAYS = 30;

    public function __construct(
        private UserRepository $userRepository,
        private OrderService $orderService,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(AdminCreateOnboardingCommand $command): Order
    {
        $now = $this->clock->now();

        // 1. Get or create user
        $user = $this->getOrCreateUser($command, $now);

        // 2. Update billing info
        $user->updateBillingInfo(
            companyName: $command->companyName,
            companyId: $command->companyId,
            companyVatId: $command->companyVatId,
            billingStreet: $command->billingStreet,
            billingCity: $command->billingCity,
            billingPostalCode: $command->billingPostalCode,
            now: $now,
        );

        if (null !== $command->birthDate) {
            $user->updateBirthDate($command->birthDate, $now);
        }

        // 3. Create order
        $order = $this->orderService->createOrder(
            user: $user,
            storageType: $command->storageType,
            place: $command->place,
            rentalType: $command->rentalType,
            startDate: $command->startDate,
            endDate: $command->endDate,
            now: $now,
            paymentFrequency: PaymentFrequency::MONTHLY,
            preSelectedStorage: $command->storage,
        );

        // 4. Mark as admin-created with signing token
        $order->markAsAdminCreated();
        $order->setPaymentMethod($command->paymentMethod);
        $order->setSigningToken(bin2hex(random_bytes(32)));
        $order->extendExpiration($now->modify('+'.self::ONBOARDING_EXPIRATION_DAYS.' days'));

        // 5. Record event for email dispatch
        $order->recordThat(new AdminOnboardingInitiated(
            orderId: $order->id,
            userId: $user->id,
            customerEmail: $user->email,
            signingToken: $order->signingToken,
            occurredOn: $now,
        ));

        return $order;
    }

    private function getOrCreateUser(AdminCreateOnboardingCommand $command, \DateTimeImmutable $now): User
    {
        $existingUser = $this->userRepository->findByEmail($command->email);

        if (null !== $existingUser) {
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

        if (null !== $command->phone) {
            $user->updateProfile($command->firstName, $command->lastName, $command->phone, $now);
        }

        $this->userRepository->save($user);

        return $user;
    }
}
