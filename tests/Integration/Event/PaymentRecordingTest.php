<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Command\CompleteOrderCommand;
use App\Command\InitiatePaymentCommand;
use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Enum\UserRole;
use App\Event\RecurringPaymentCharged;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Tests payment recording for self-billing purposes.
 *
 * Payment records are created when:
 * 1. Initial order is paid (OrderPaid event)
 * 2. Recurring payments are charged (RecurringPaymentCharged event)
 */
class PaymentRecordingTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private MessageBusInterface $eventBus;
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
        $this->eventBus = $container->get('test.event.bus');
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testPaymentRecordedOnOrderPaid(): void
    {
        // Setup: create landlord with storage
        $landlord = $this->createLandlord('landlord-orderpaid@test.com');
        $place = $this->getFixturePlace();
        $storageType = $this->getFixtureStorageType();
        $storage = $this->createStorageWithOwner($storageType, $place, 'PAY1', $landlord);
        $tenant = $this->getFixtureTenant();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        // Create order using this storage (need to use OrderService which assigns available storage)
        // Since we need the specific storage, we'll use a workaround
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        // The storage assigned may not be our landlord's storage, so let's check payment recording
        // works regardless of which storage is used
        $assignedStorage = $order->storage;

        // Count payments before
        $paymentsBefore = $this->countPaymentsForStorage($assignedStorage);

        // Initiate and complete payment
        $this->commandBus->dispatch(new InitiatePaymentCommand(
            order: $order,
            returnUrl: 'https://example.com/return',
            notificationUrl: 'https://example.com/webhook',
        ));

        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);
        $this->goPayClient->simulatePaymentPaid($paymentId);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Count payments after
        $paymentsAfter = $this->countPaymentsForStorage($assignedStorage);

        $this->assertSame($paymentsBefore + 1, $paymentsAfter, 'Payment should be recorded when order is paid');

        // Verify payment details
        $payment = $this->getLastPaymentForStorage($assignedStorage);
        $this->assertNotNull($payment);
        $this->assertSame($order->totalPrice, $payment->amount);
        $this->assertNotNull($payment->order);
        $this->assertTrue($payment->order->id->equals($order->id));
    }

    public function testPaymentRecordedOnRecurringPaymentCharged(): void
    {
        // Create contract with recurring payment setup
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        // Count payments before
        $paymentsBefore = $this->countPaymentsForStorage($contract->storage);

        // Dispatch RecurringPaymentCharged event directly
        $this->eventBus->dispatch(new RecurringPaymentCharged(
            contractId: $contract->id,
            paymentId: 123456,
            amount: 50000, // 500 CZK
            occurredOn: $now,
        ));

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Count payments after
        $paymentsAfter = $this->countPaymentsForStorage($contract->storage);

        $this->assertSame($paymentsBefore + 1, $paymentsAfter, 'Payment should be recorded when recurring payment is charged');

        // Verify payment details
        $payment = $this->getLastPaymentForStorage($contract->storage);
        $this->assertNotNull($payment);
        $this->assertSame(50000, $payment->amount);
        $this->assertNotNull($payment->contract);
        $this->assertTrue($payment->contract->id->equals($contract->id));
        $this->assertNull($payment->order); // Recurring payments don't have order
    }

    public function testPaymentLinkedToCorrectStorage(): void
    {
        // Create landlord with storage
        $landlord = $this->createLandlord('landlord-storage@test.com');
        $place = $this->getFixturePlace();
        $storageType = $this->getFixtureStorageType();
        $storage = $this->createStorageWithOwner($storageType, $place, 'STR1', $landlord);

        // Create contract for this storage (manual setup to ensure we use our storage)
        $tenant = $this->getFixtureTenant();
        $now = $this->clock->now();

        // Create a payment directly
        $payment = new Payment(
            id: Uuid::v7(),
            order: null,
            contract: null,
            storage: $storage,
            amount: 30000,
            paidAt: $now,
            createdAt: $now,
        );
        $this->entityManager->persist($payment);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Verify the payment is linked to the landlord's storage
        $payments = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->join('p.storage', 's')
            ->where('s.owner = :landlord')
            ->setParameter('landlord', $landlord)
            ->getQuery()
            ->getResult();

        $this->assertCount(1, $payments);
        $this->assertTrue($payments[0]->storage->id->equals($storage->id));
    }

    private function createLandlord(string $email): User
    {
        $user = new User(
            id: Uuid::v7(),
            email: $email,
            password: 'password',
            firstName: 'Test',
            lastName: 'Landlord',
            createdAt: $this->clock->now(),
        );
        $user->markAsVerified($this->clock->now());
        $user->changeRole(UserRole::LANDLORD, $this->clock->now());
        $user->popEvents();
        $this->entityManager->persist($user);

        return $user;
    }

    private function createStorageWithOwner(StorageType $storageType, Place $place, string $number, User $owner): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->clock->now(),
        );
        $storage->assignOwner($owner, $this->clock->now());
        $this->entityManager->persist($storage);

        return $storage;
    }

    private function getFixturePlace(): Place
    {
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        return $place;
    }

    private function getFixtureStorageType(): StorageType
    {
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);

        return $storageType;
    }

    private function getFixtureTenant(): User
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);

        return $tenant;
    }

    private function countPaymentsForStorage(Storage $storage): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.storage = :storage')
            ->setParameter('storage', $storage)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function getLastPaymentForStorage(Storage $storage): ?Payment
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.storage = :storage')
            ->setParameter('storage', $storage)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')  // UUID v7 is sortable by creation time
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

        // Process notification to mark order as paid
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        // Set parent payment ID
        $order->setGoPayParentPaymentId($paymentId);
        $this->entityManager->flush();

        // Complete order to create contract
        $envelope = $this->commandBus->dispatch(new CompleteOrderCommand($order));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);

        /** @var Contract $contract */
        $contract = $handledStamp->getResult();

        return $contract;
    }
}
