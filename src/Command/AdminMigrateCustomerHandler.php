<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdminMigrateCustomerHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private OrderService $orderService,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
        private string $contractsDirectory,
    ) {
    }

    public function __invoke(AdminMigrateCustomerCommand $command): Contract
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

        // 3. Create order — locked-in monthly is individualMonthlyAmount (when set)
        // or the storage default; the lump sum is recorded separately as a Payment.
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
            monthlyPriceOverride: $command->individualMonthlyAmount,
        );

        // 4. Mark as admin-created with external payment
        $order->markAsAdminCreated();
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);

        // Default paidThroughDate to endDate for LIMITED rentals when omitted
        $paidThroughDate = $command->paidThroughDate ?? $command->endDate;
        $order->setOnboardingBillingTerms($command->individualMonthlyAmount, $paidThroughDate);

        // 5. Accept terms + reserve storage
        $order->acceptTerms($now);
        $order->reserve($now);

        // 6. Confirm payment with the lump-sum override → records Payment for the
        // amount actually paid externally, while Order.firstPaymentPrice keeps
        // the locked-in monthly the cron will replay later.
        $this->orderService->confirmPayment($order, $command->paidAt, $command->totalPrice);

        // 7. Complete order → create Contract, storage → OCCUPIED. The contract
        // inherits individualMonthlyAmount + paidThroughDate via OrderService.
        $contract = $this->orderService->completeOrder($order, $now);

        // 8. Move uploaded contract document and attach to contract
        $contractPath = $this->moveContractDocument($command->contractDocumentPath, $contract);
        $contract->attachDocument($contractPath, $now);
        $contract->sign($now);

        return $contract;
    }

    private function getOrCreateUser(AdminMigrateCustomerCommand $command, \DateTimeImmutable $now): User
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

    private function moveContractDocument(string $sourcePath, Contract $contract): string
    {
        if (!is_dir($this->contractsDirectory)) {
            mkdir($this->contractsDirectory, 0755, true);
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'pdf';
        $filename = sprintf('contract_%s.%s', $contract->id->toRfc4122(), $extension);
        $targetPath = $this->contractsDirectory.'/'.$filename;

        rename($sourcePath, $targetPath);

        return $targetPath;
    }
}
