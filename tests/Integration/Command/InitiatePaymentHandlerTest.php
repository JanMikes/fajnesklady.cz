<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\InitiatePaymentCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use App\Value\GoPayPayment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class InitiatePaymentHandlerTest extends KernelTestCase
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

    public function testInitiatePaymentForAutoRecurringOrderCreatesRecurringPayment(): void
    {
        // Default billing mode is AUTO_RECURRING (GOPAY card payments).
        $order = $this->createOrder();

        $envelope = $this->commandBus->dispatch(new InitiatePaymentCommand(
            order: $order,
            returnUrl: 'https://example.com/return',
            notificationUrl: 'https://example.com/webhook',
        ));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        /** @var GoPayPayment $payment */
        $payment = $handledStamp->getResult();

        $this->assertInstanceOf(GoPayPayment::class, $payment);
        $this->assertNotEmpty($payment->gwUrl);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
        $this->assertSame($payment->id, $order->goPayPaymentId);
    }

    public function testInitiatePaymentForNonRecurringOrderThrows(): void
    {
        // Spec 076: cards only establish recurring payments. A short
        // bank-transfer (ONE_TIME) order must never reach the GoPay gateway.
        $order = $this->createOrder(endDateModifier: '+14 days');
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::ONE_TIME);

        $exceptionThrown = false;

        try {
            $this->commandBus->dispatch(new InitiatePaymentCommand(
                order: $order,
                returnUrl: 'https://example.com/return',
                notificationUrl: 'https://example.com/webhook',
            ));
        } catch (HandlerFailedException $e) {
            $exceptionThrown = true;
            // The LogicException is wrapped in HandlerFailedException
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\LogicException::class, $previous);
            $this->assertSame('Card payments are recurring-only; non-recurring orders are paid by bank transfer.', $previous->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected HandlerFailedException to be thrown');
        $this->assertSame([], $this->goPayClient->getCreatedPayments());
    }

    private function createOrder(string $endDateModifier = '+12 months'): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $startDate->modify($endDateModifier);

        return $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $endDate,
            $now,
        );
    }
}
