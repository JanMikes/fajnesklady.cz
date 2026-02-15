<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ChargeRecurringPaymentCommand;
use App\Command\InitiatePaymentCommand;
use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class ChargeRecurringPaymentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderService $orderService;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testChargeRecurringPaymentUpdatesContract(): void
    {
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        $this->assertNull($contract->lastBilledAt, 'Contract should not be billed yet');

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->entityManager->clear();
        $refreshedContract = $this->entityManager->find(Contract::class, $contract->id);

        // Verify billing was recorded
        $this->assertNotNull($refreshedContract->lastBilledAt);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $refreshedContract->lastBilledAt->format('Y-m-d H:i:s'));
        // Next billing date should be set to now + 1 month (monthly frequency)
        $expectedNextBilling = $now->modify('+1 month');
        $this->assertEquals($expectedNextBilling->format('Y-m-d H:i:s'), $refreshedContract->nextBillingDate->format('Y-m-d H:i:s'));
        $this->assertSame(0, $refreshedContract->failedBillingAttempts);
    }

    public function testChargeRecurringPaymentThrowsOnError(): void
    {
        $contract = $this->createContractWithRecurringPayment();

        // Simulate GoPay failure
        $this->goPayClient->willFailNextRecurrence();

        $exceptionThrown = false;

        try {
            $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $e) {
            $exceptionThrown = true;
            // The GoPayException is wrapped in HandlerFailedException
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\App\Service\GoPay\GoPayException::class, $previous);
            $this->assertStringContainsString('Simulated recurrence failure', $previous->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected HandlerFailedException to be thrown');
    }

    private function createContractWithRecurringPayment(): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        // Create unlimited order
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::UNLIMITED,
            $startDate,
            null,
            $now,
            PaymentFrequency::MONTHLY,
        );

        // Accept terms before payment
        $order->acceptTerms($now);

        // Initiate payment
        $this->commandBus->dispatch(new InitiatePaymentCommand(
            order: $order,
            returnUrl: 'https://example.com/return',
            notificationUrl: 'https://example.com/webhook',
        ));

        // Simulate payment success
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);
        $this->goPayClient->simulatePaymentPaid($paymentId);

        // Process notification — auto-completes order and creates contract (terms accepted)
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch contract created by auto-completion
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        return $contract;
    }
}
