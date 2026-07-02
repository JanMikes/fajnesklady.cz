<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProcessPaymentNotificationHandlerManualBillingTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderService $orderService;
    private MessageBusInterface $commandBus;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;
    private ProvideIdentity $identity;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->orderService = $container->get(OrderService::class);
        $this->commandBus = $container->get('test.command.bus');
        $this->clock = $container->get(ClockInterface::class);
        $this->identity = $container->get(ProvideIdentity::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testWebhookForManualPaymentAdvancesContractBillingDates(): void
    {
        $contract = $this->createManualContract();
        $originalNextBilling = $contract->nextBillingDate;
        self::assertNotNull($originalNextBilling);

        $request = new ManualPaymentRequest(
            id: $this->identity->next(),
            contract: $contract,
            periodStart: $originalNextBilling,
            periodEnd: $originalNextBilling->modify('+1 month'),
            amount: 35000,
            createdAt: $this->clock->now(),
        );
        $request->attachGoPayPayment('gp_manual_test_123', 'https://gw/manual_test_123');
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->goPayClient->seedRecurrenceStatus('gp_manual_test_123', 'PAID', '', 35000);
        $this->goPayClient->simulatePaymentPaid('gp_manual_test_123');

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand('gp_manual_test_123'));

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertNotEquals(
            $originalNextBilling->format('Y-m-d'),
            $reloaded->nextBillingDate?->format('Y-m-d'),
            'Manual-billing webhook must advance nextBillingDate.',
        );
        self::assertNotNull($reloaded->lastBilledAt);
        self::assertSame(0, $reloaded->failedBillingAttempts);

        $reloadedRequest = $this->entityManager->find(ManualPaymentRequest::class, $request->id);
        self::assertNotNull($reloadedRequest);
        self::assertSame('paid', $reloadedRequest->status);
        self::assertNotNull($reloadedRequest->paidAt);

        $payment = $this->entityManager->getRepository(Payment::class)
            ->findOneBy(['goPayPaymentId' => 'gp_manual_test_123']);
        self::assertNotNull($payment, 'A Payment row must be persisted on a manual-billing webhook.');
    }

    private function createManualContract(): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $now->modify('+1 day'),
            $now->modify('+6 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->markPaid($now);
        $contract = $this->orderService->completeOrder($order, $now);

        $this->entityManager->flush();

        $this->entityManager->createQueryBuilder()
            ->update(Contract::class, 'c')
            ->set('c.nextBillingDate', ':next')
            ->where('c.id = :id')
            ->setParameter('next', new \DateTimeImmutable('2025-07-15'))
            ->setParameter('id', $contract->id)
            ->getQuery()
            ->execute();

        $this->entityManager->clear();

        $refetched = $this->entityManager->find(Contract::class, $contract->id);
        \assert($refetched instanceof Contract);

        return $refetched;
    }
}
