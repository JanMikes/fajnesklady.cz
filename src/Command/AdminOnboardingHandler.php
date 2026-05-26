<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Event\AdminOnboardingInitiated;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use App\Service\Payment\VariableSymbolGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdminOnboardingHandler
{
    private const int ONBOARDING_EXPIRATION_DAYS = 30;

    public function __construct(
        private UserRepository $userRepository,
        private OrderService $orderService,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
        private VariableSymbolGenerator $variableSymbolGenerator,
        private string $contractsDirectory,
    ) {
    }

    public function __invoke(AdminOnboardingCommand $command): Order
    {
        $now = $this->clock->now();

        $user = $this->getOrCreateUser($command, $now);

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

        $order = $this->orderService->createOrder(
            user: $user,
            storageType: $command->storageType,
            place: $command->place,
            rentalType: $command->rentalType,
            startDate: $command->startDate,
            endDate: $command->endDate,
            now: $now,
            paymentFrequency: $command->paymentFrequency,
            preSelectedStorage: $command->storage,
            monthlyPriceOverride: $command->individualMonthlyAmount,
            expectedDuration: $command->expectedDuration,
        );

        $order->setBillingMode($command->billingMode);
        $order->markAsAdminCreated();

        $forceExternal = 0 === $command->individualMonthlyAmount || null !== $command->paidThroughDate;
        $effectivePaymentMethod = $forceExternal ? PaymentMethod::EXTERNAL : $command->paymentMethod;
        $order->setPaymentMethod($effectivePaymentMethod);

        if (PaymentMethod::BANK_TRANSFER === $effectivePaymentMethod) {
            $vs = null !== $command->variableSymbolOverride && '' !== $command->variableSymbolOverride
                ? $command->variableSymbolOverride
                : $this->variableSymbolGenerator->generate($order->id);
            $order->assignVariableSymbol($vs);
        }

        $createdByAdmin = $this->userRepository->get($command->createdByAdminId);
        $order->setOnboardingBillingTerms(
            individualMonthlyAmount: $command->individualMonthlyAmount,
            paidThroughDate: $command->paidThroughDate,
            createdByAdmin: $createdByAdmin,
        );

        if (null !== $command->uploadedContractPath) {
            $contractPath = $this->moveContractDocument($command->uploadedContractPath, $order);
            $order->setUploadedContractDocumentPath($contractPath);
        }

        $order->setSigningToken(bin2hex(random_bytes(32)));
        $order->extendExpiration($now->modify('+'.self::ONBOARDING_EXPIRATION_DAYS.' days'));

        $order->recordThat(new AdminOnboardingInitiated(
            orderId: $order->id,
            userId: $user->id,
            customerEmail: $user->email,
            signingToken: $order->signingToken,
            occurredOn: $now,
        ));

        return $order;
    }

    private function moveContractDocument(string $sourcePath, Order $order): string
    {
        if (!is_dir($this->contractsDirectory)) {
            mkdir($this->contractsDirectory, 0755, true);
        }

        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'pdf';
        $filename = sprintf('contract_%s.%s', $order->id->toRfc4122(), $extension);
        $targetPath = $this->contractsDirectory.'/'.$filename;

        rename($sourcePath, $targetPath);

        return $targetPath;
    }

    private function getOrCreateUser(AdminOnboardingCommand $command, \DateTimeImmutable $now): User
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
