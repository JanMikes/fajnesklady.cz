<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ChooseOnboardingPaymentCommand;
use App\Command\ChooseOnboardingPaymentHandler;
use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\AuditLogger;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class ChooseOnboardingPaymentHandlerTest extends KernelTestCase
{
    private OrderService $orderService;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->orderService = $container->get(OrderService::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testCardChoiceLocksAutoRecurringMonthlyKeepingVs(): void
    {
        $order = $this->createDeferredOrder('+6 months');

        $this->commandBus->dispatch(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::GOPAY,
            PaymentFrequency::MONTHLY,
        ));

        $this->assertSame(PaymentMethod::GOPAY, $order->paymentMethod);
        $this->assertSame(PaymentFrequency::MONTHLY, $order->paymentFrequency);
        $this->assertSame(BillingMode::AUTO_RECURRING, $order->billingMode);
        // Spec 089: every order carries a VS regardless of the chosen method.
        $this->assertNotNull($order->variableSymbol);
        $this->assertGreaterThan(0, $order->firstPaymentPrice);
        $this->assertFalse($order->isAwaitingPaymentChoice());
    }

    public function testBankYearlyChoiceLocksManualRecurringWithVs(): void
    {
        $order = $this->createDeferredOrder('+13 months');

        $this->commandBus->dispatch(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::BANK_TRANSFER,
            PaymentFrequency::YEARLY,
        ));

        $this->assertSame(PaymentMethod::BANK_TRANSFER, $order->paymentMethod);
        $this->assertTrue($order->isYearlyFrequency());
        $this->assertSame(BillingMode::MANUAL_RECURRING, $order->billingMode);
        $this->assertNotNull($order->variableSymbol);
        $this->assertFalse($order->isAwaitingPaymentChoice());
    }

    public function testCardOnShortRentalThrows(): void
    {
        $order = $this->createDeferredOrder('+20 days');

        $this->expectException(\DomainException::class);
        $this->handler()(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::GOPAY,
            PaymentFrequency::MONTHLY,
        ));
    }

    public function testCancelledOrderThrows(): void
    {
        $order = $this->createDeferredOrder('+6 months');
        $order->cancel($this->clock->now());
        $this->entityManager->flush();

        $this->expectException(\DomainException::class);
        $this->handler()(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::GOPAY,
            PaymentFrequency::MONTHLY,
        ));
    }

    public function testExternalMethodThrows(): void
    {
        $order = $this->createDeferredOrder('+6 months');

        $this->expectException(\DomainException::class);
        $this->handler()(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::EXTERNAL,
            PaymentFrequency::MONTHLY,
        ));
    }

    public function testNonDeferredOrderThrows(): void
    {
        // A normal (non-deferred) order was never marked customerChoosesPayment,
        // so its locked price must never be rewritten by a customer choice.
        $order = $this->createDeferredOrder('+6 months', deferred: false);
        $this->assertFalse($order->isAwaitingPaymentChoice());

        $this->expectException(\DomainException::class);
        $this->handler()(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::BANK_TRANSFER,
            PaymentFrequency::MONTHLY,
        ));
    }

    public function testChoiceIsReEditableUntilSigned(): void
    {
        $order = $this->createDeferredOrder('+13 months');

        // First choice: bank yearly.
        $this->handler()(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::BANK_TRANSFER,
            PaymentFrequency::YEARLY,
        ));
        $vsBeforeFlip = $order->variableSymbol;
        $this->assertNotNull($vsBeforeFlip);

        // Customer changes their mind to card monthly — still allowed (unsigned).
        $this->handler()(new ChooseOnboardingPaymentCommand(
            $order,
            PaymentMethod::GOPAY,
            PaymentFrequency::MONTHLY,
        ));

        $this->assertSame(PaymentMethod::GOPAY, $order->paymentMethod);
        $this->assertSame(BillingMode::AUTO_RECURRING, $order->billingMode);
        $this->assertSame($vsBeforeFlip, $order->variableSymbol, 'switching to card keeps the VS (spec 089)');
    }

    private function handler(): ChooseOnboardingPaymentHandler
    {
        $container = static::getContainer();

        return new ChooseOnboardingPaymentHandler(
            $container->get(PriceCalculator::class),
            $container->get(AuditLogger::class),
        );
    }

    private function createDeferredOrder(string $endModifier, bool $deferred = true): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        $order = $this->orderService->createOrder(
            user: $tenant,
            storageType: $storageType,
            place: $place,
            startDate: $startDate,
            endDate: $startDate->modify($endModifier),
            now: $now,
            paymentFrequency: PaymentFrequency::MONTHLY,
        );
        $order->markAsAdminCreated();
        if ($deferred) {
            $order->markCustomerChoosesPayment();
        }
        $this->entityManager->flush();

        return $order;
    }
}
