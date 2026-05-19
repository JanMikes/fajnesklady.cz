<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DispatchOnboardingReminderCommand;
use App\Entity\OnboardingReminderSent;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
use App\Repository\OnboardingReminderSentRepository;
use App\Service\Onboarding\OnboardingReminderSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class DispatchOnboardingReminderHandlerTest extends KernelTestCase
{
    private MessageBusInterface $commandBus;
    private OnboardingReminderSentRepository $reminderRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get('test.command.bus');
        $this->reminderRepository = $container->get(OnboardingReminderSentRepository::class);
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    public function testFirstDispatchRecordsReminderRow(): void
    {
        $order = $this->loadSignedUnpaidOrder();

        $this->commandBus->dispatch(new DispatchOnboardingReminderCommand(
            orderId: $order->id,
            stage: OnboardingReminderSchedule::STAGE_D_PLUS_2,
        ));

        $row = $this->reminderRepository->findByOrderAndStage($order, OnboardingReminderSchedule::STAGE_D_PLUS_2);
        $this->assertInstanceOf(OnboardingReminderSent::class, $row);
    }

    public function testSecondDispatchSameStageIsNoOp(): void
    {
        $order = $this->loadSignedUnpaidOrder();

        $this->commandBus->dispatch(new DispatchOnboardingReminderCommand(
            orderId: $order->id,
            stage: OnboardingReminderSchedule::STAGE_D_PLUS_2,
        ));
        $this->commandBus->dispatch(new DispatchOnboardingReminderCommand(
            orderId: $order->id,
            stage: OnboardingReminderSchedule::STAGE_D_PLUS_2,
        ));

        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(OnboardingReminderSent::class, 'r')
            ->where('r.order = :order')
            ->andWhere('r.stage = :stage')
            ->setParameter('order', $order)
            ->setParameter('stage', OnboardingReminderSchedule::STAGE_D_PLUS_2)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, (int) $count);
    }

    public function testStateDriftSilentlySkipsWithoutRecording(): void
    {
        $order = $this->loadSignedUnpaidOrder();
        // Flip state to PAID — exactly the race the handler must guard.
        $order->markPaid(new \DateTimeImmutable('2025-06-15 12:00:00'));
        $order->popEvents();
        $this->entityManager->flush();

        $this->commandBus->dispatch(new DispatchOnboardingReminderCommand(
            orderId: $order->id,
            stage: OnboardingReminderSchedule::STAGE_D_PLUS_5,
        ));

        $row = $this->reminderRepository->findByOrderAndStage($order, OnboardingReminderSchedule::STAGE_D_PLUS_5);
        $this->assertNull($row);
    }

    private function loadSignedUnpaidOrder(): Order
    {
        // Pick an existing fixture order and rewrite it into the
        // signed-but-unpaid GoPay state we need.
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :reserved')
            ->setParameter('reserved', OrderStatus::RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        $order->markAsAdminCreated();
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $order->attachSignature('/tmp/sig.png', SigningMethod::DRAW, null, null, 'Praha', $now->modify('-2 days'));
        $order->extendExpiration($now->modify('+30 days'));
        $this->entityManager->flush();

        return $order;
    }
}
