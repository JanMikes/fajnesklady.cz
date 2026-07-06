<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CompleteOrderCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class CompleteOrderHandlerTest extends KernelTestCase
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

    public function testFreeManualContractGetsNoBillingAnchor(): void
    {
        $order = $this->createPaidManualOrder();
        $order->setOnboardingBillingTerms(0, null);

        $contract = $this->completeViaBus($order);

        $this->assertTrue($contract->isFree());
        $this->assertNull($contract->nextBillingDate, 'free contracts must never enter the manual-billing track');
    }

    public function testExternallyPrepaidContractAnchorsBillingAfterPaidThrough(): void
    {
        $paidThrough = $this->clock->now()->modify('+5 days')->setTime(0, 0, 0);

        $order = $this->createPaidManualOrder();
        $order->setOnboardingBillingTerms(null, $paidThrough);

        $contract = $this->completeViaBus($order);

        $this->assertEquals($paidThrough, $contract->paidThroughDate);
        $this->assertEquals($paidThrough->modify('+1 day'), $contract->nextBillingDate);
    }

    private function createPaidManualOrder(): Order
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
            endDate: $startDate->modify('+12 months'),
            now: $now,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->markAsAdminCreated();

        $order->reserve($now);
        $this->orderService->processPayment($order);
        $this->orderService->confirmPayment($order, $now);

        return $order;
    }

    private function completeViaBus(Order $order): Contract
    {
        $envelope = $this->commandBus->dispatch(new CompleteOrderCommand(order: $order));
        $handledStamp = $envelope->last(HandledStamp::class);
        \assert(null !== $handledStamp);
        $contract = $handledStamp->getResult();
        \assert($contract instanceof Contract);

        return $contract;
    }
}
