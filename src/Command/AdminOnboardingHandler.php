<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
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

        $createdByAdmin = $this->userRepository->get($command->createdByAdminId);

        if ($command->letCustomerChoosePayment) {
            // Spec 088: deferred choice. Provisional MONTHLY standard-ceník order;
            // the customer picks method + frequency at signing
            // (ChooseOnboardingPaymentHandler), which recomputes firstPaymentPrice
            // + billingMode + VS. No method / mode / VS / external-force here.
            $order = $this->orderService->createOrder(
                user: $user,
                storageType: $command->storageType,
                place: $command->place,
                startDate: $command->startDate,
                endDate: $command->endDate,
                now: $now,
                paymentFrequency: PaymentFrequency::MONTHLY,
                preSelectedStorage: $command->storage,
                monthlyPriceOverride: null,
            );
            $order->markAsAdminCreated();
            $order->markCustomerChoosesPayment();
            $order->setOnboardingBillingTerms(
                individualMonthlyAmount: null,
                paidThroughDate: null,
                createdByAdmin: $createdByAdmin,
            );
        } else {
            $paymentMethod = $command->paymentMethod;
            $paymentFrequency = $command->paymentFrequency;
            \assert($paymentMethod instanceof PaymentMethod);
            \assert($paymentFrequency instanceof PaymentFrequency);

            $order = $this->orderService->createOrder(
                user: $user,
                storageType: $command->storageType,
                place: $command->place,
                startDate: $command->startDate,
                endDate: $command->endDate,
                now: $now,
                paymentFrequency: $paymentFrequency,
                preSelectedStorage: $command->storage,
                monthlyPriceOverride: $command->individualMonthlyAmount,
            );

            $order->markAsAdminCreated();

            $isFreeOrPrepaid = 0 === $command->individualMonthlyAmount || null !== $command->paidThroughDate;
            $hasDebt = null !== $command->debtInHaler && $command->debtInHaler > 0;
            $forceExternal = $isFreeOrPrepaid && !$hasDebt;
            $effectivePaymentMethod = $forceExternal ? PaymentMethod::EXTERNAL : $paymentMethod;
            $order->setPaymentMethod($effectivePaymentMethod);

            // Prepaid rental billing always runs on the manual (bank-transfer request)
            // track — even when the method radio stays GOPAY/BANK_TRANSFER for a debt
            // payment, no card token is ever established for the rental itself.
            $rentalDays = (int) $command->startDate->diff($command->endDate)->days;
            $billingMode = null !== $command->paidThroughDate
                ? BillingMode::derive(PaymentMethod::EXTERNAL, $paymentFrequency, $rentalDays)
                : BillingMode::derive($effectivePaymentMethod, $paymentFrequency, $rentalDays);
            $order->setBillingMode($billingMode);

            if (PaymentMethod::BANK_TRANSFER === $effectivePaymentMethod) {
                $vs = null !== $command->variableSymbolOverride && '' !== $command->variableSymbolOverride
                    ? $command->variableSymbolOverride
                    : $this->variableSymbolGenerator->generate($order->id);
                $order->assignVariableSymbol($vs);
            }

            $order->setOnboardingBillingTerms(
                individualMonthlyAmount: $command->individualMonthlyAmount,
                paidThroughDate: $command->paidThroughDate,
                createdByAdmin: $createdByAdmin,
            );
        }

        if (null !== $command->debtInHaler && $command->debtInHaler > 0) {
            $order->setOnboardingDebt($command->debtInHaler);
        }

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
