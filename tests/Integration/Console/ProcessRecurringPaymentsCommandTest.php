<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessRecurringPaymentsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderService $orderService;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;
    private Application $application;

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

        $this->application = new Application(self::$kernel);
    }

    public function testGoPayApiFailureIsRecordedAsFailedAttempt(): void
    {
        // Regression: Symfony Messenger's HandlerFailedException must be unwrapped
        // so the typed catch fires and recordFailedBillingAttempt() runs. Before
        // the fix the wrapped exception fell through to the generic `(unexpected)`
        // branch and the failure counter never incremented — meaning the
        // retry-then-cancel ladder never engaged for any GoPay error.
        $contract = $this->createDueContract();
        $this->goPayClient->willFailNextRecurrence();

        $tester = new CommandTester($this->application->find('app:process-recurring-payments'));
        $tester->execute([]);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);

        $this->assertSame(
            1,
            $refreshed->failedBillingAttempts,
            'GoPayException must be unwrapped from HandlerFailedException and counted as a failure.',
        );
        $this->assertNotNull($refreshed->lastBillingFailedAt);
    }

    public function testPollingTimeoutDoesNotIncrementFailureCounter(): void
    {
        // Polling-timeout (CREATED state never resolves) must NOT count as a
        // billing failure: the webhook will reconcile, and the in-flight ID is
        // recorded so the next cron run avoids double-charging.
        $contract = $this->createDueContract();
        $this->goPayClient->willStayPendingForRecurrence();

        $tester = new CommandTester($this->application->find('app:process-recurring-payments'));
        $tester->execute([]);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);

        $this->assertSame(0, $refreshed->failedBillingAttempts, 'Polling timeout is not a failure.');
        $this->assertNotNull(
            $refreshed->pendingRecurringPaymentId,
            'In-flight payment ID must be recorded for next-run reconciliation.',
        );
        $this->assertNull($refreshed->lastBilledAt);
    }

    private function createDueContract(): Contract
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
            $tenant,
            $storageType,
            $place,
            RentalType::UNLIMITED,
            $startDate,
            null,
            $now,
            PaymentFrequency::MONTHLY,
        );
        $order->acceptTerms($now);

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

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();
        \assert($contract instanceof Contract);

        // Force the contract to be due NOW so findDueForBilling() picks it up
        // (ProcessPaymentNotificationCommand seeds nextBillingDate ~1 month out).
        $this->entityManager->createQueryBuilder()
            ->update(Contract::class, 'c')
            ->set('c.nextBillingDate', ':due')
            ->where('c.id = :id')
            ->setParameter('due', $this->clock->now()->modify('-1 minute'))
            ->setParameter('id', $contract->id)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();

        $refetched = $this->entityManager->find(Contract::class, $contract->id);
        \assert($refetched instanceof Contract);

        return $refetched;
    }
}
