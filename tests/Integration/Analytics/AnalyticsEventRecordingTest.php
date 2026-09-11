<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Command\ConfirmOrderPaymentCommand;
use App\Command\CreateOrderCommand;
use App\Entity\AnalyticsEvent;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\StorageStatus;
use App\Service\Analytics\AnalyticsEventFlusher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The measurement outbox (GTM order/payment events).
 *
 * What is guarded here is money reporting: every case below either produces a
 * Google Ads conversion or must not. A false positive bills a campaign for a
 * rental that never happened; a wrong value misprices the bidding.
 */
final class AnalyticsEventRecordingTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->commandBus = $container->get('test.command.bus');
    }

    public function testConfirmedPaymentRecordsAConversionEventWithTheAmountInCrowns(): void
    {
        $order = $this->orderByStorageNumber('B1');
        $priceInHaler = $order->firstPaymentPrice;

        $this->commandBus->dispatch(new ConfirmOrderPaymentCommand($order));

        $event = $this->eventFor($order, AnalyticsEvent::FIRST_PAYMENT_SUCCESS);

        self::assertNotNull($event, 'Potvrzená platba musí zapsat konverzní událost.');
        self::assertSame($order->id->toRfc4122(), $event->payload['order_id']);
        self::assertSame('CZK', $event->payload['currency']);
        self::assertSame(
            round($priceInHaler / 100, 2),
            $event->payload['value'],
            'Hodnota musí být v korunách, ne v haléřích — jinak Ads vidí stonásobek.',
        );
        self::assertNull($event->pushedAt, 'Událost čeká na prohlížeč, dokud ji někdo nevyzvedne.');
    }

    public function testCreatingAnOrderRecordsTheCreationEventWithPlaceAndStorageType(): void
    {
        $order = $this->createOrderThroughTheBus();

        $event = $this->eventFor($order, AnalyticsEvent::ORDER_CREATED);

        self::assertNotNull($event, 'Vytvořená objednávka musí zapsat událost order_created.');
        self::assertSame($order->id->toRfc4122(), $event->payload['order_id']);
        self::assertSame('CZK', $event->payload['currency']);
        self::assertSame($order->storage->place->id->toRfc4122(), $event->payload['place_id']);
        self::assertSame($order->storage->place->name, $event->payload['place_name']);
        self::assertSame($order->storage->storageType->name, $event->payload['storage_type_name']);
        self::assertSame($order->startDate->format('Y-m-d'), $event->payload['rental_start_date']);
        self::assertSame($order->endDate?->format('Y-m-d'), $event->payload['rental_end_date']);
        self::assertSame(round($order->firstPaymentPrice / 100, 2), $event->payload['value']);
    }

    public function testExternallySettledPaymentIsNotAConversion(): void
    {
        $order = $this->orderByStorageNumber('B1');
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $this->entityManager->flush();

        $this->commandBus->dispatch(new ConfirmOrderPaymentCommand($order));

        self::assertNull(
            $this->eventFor($order, AnalyticsEvent::FIRST_PAYMENT_SUCCESS),
            'Platba mimo systém není konverze — žádné peníze neprošly webem.',
        );
    }

    public function testFlushingHandsTheEventOverExactlyOnce(): void
    {
        $order = $this->createOrderThroughTheBus();
        $this->commandBus->dispatch(new ConfirmOrderPaymentCommand($order));

        $flusher = static::getContainer()->get(AnalyticsEventFlusher::class);

        $first = $flusher->flushFor($order);
        $names = array_column($first, 'name');
        self::assertContains(AnalyticsEvent::FIRST_PAYMENT_SUCCESS, $names);
        self::assertContains(AnalyticsEvent::ORDER_CREATED, $names);

        self::assertSame([], $flusher->flushFor($order), 'Druhé načtení stránky nesmí konverzi poslat znovu.');
    }

    public function testCreationEventIsOrderedBeforeThePaymentEvent(): void
    {
        $order = $this->createOrderThroughTheBus();
        $this->commandBus->dispatch(new ConfirmOrderPaymentCommand($order));

        $flushed = static::getContainer()->get(AnalyticsEventFlusher::class)->flushFor($order);

        self::assertSame(
            [AnalyticsEvent::ORDER_CREATED, AnalyticsEvent::FIRST_PAYMENT_SUCCESS],
            array_column($flushed, 'name'),
            'Když obě události čekají (typicky bankovní převod), musí odejít ve správném pořadí.',
        );
    }

    /**
     * Fixtures build entities directly and so never reach the messenger
     * envelope — DispatchDomainEventsMiddleware only runs on a bus, so a
     * fixture order has no OrderCreated. Anything asserting on the creation
     * event has to place a real one.
     */
    private function createOrderThroughTheBus(): Order
    {
        $storage = $this->freeStorage();

        $envelope = $this->commandBus->dispatch(new CreateOrderCommand(
            user: $storage->place->owner ?? $this->anyTenant(),
            storageType: $storage->storageType,
            place: $storage->place,
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2025-08-01'),
            paymentFrequency: PaymentFrequency::MONTHLY,
            preSelectedStorage: $storage,
        ));

        $handled = $envelope->last(HandledStamp::class);
        \assert(null !== $handled);
        $order = $handled->getResult();
        \assert($order instanceof Order);

        return $order;
    }

    private function freeStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.status = :available')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('available', StorageStatus::AVAILABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage);

        return $storage;
    }

    private function anyTenant(): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', 'tenant@example.com')
            ->getQuery()
            ->getOneOrNullResult();

        \assert($user instanceof User);

        return $user;
    }

    private function orderByStorageNumber(string $number): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        return $order;
    }

    private function eventFor(Order $order, string $name): ?AnalyticsEvent
    {
        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(AnalyticsEvent::class, 'e')
            ->where('e.order = :order')
            ->andWhere('e.name = :name')
            ->setParameter('order', $order)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
