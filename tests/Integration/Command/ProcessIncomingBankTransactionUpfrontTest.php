<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessIncomingBankTransactionCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use App\Value\FioBankTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Spec 078 — FIO half of the upfront golden path: an incoming bank transaction
 * with the order's VS and the whole rental total completes the order into a
 * ONE_TIME contract with no billing dates. (The web half — /prijmout rendering
 * and the payment-page QR — lives in OrderAcceptControllerTest.)
 */
class ProcessIncomingBankTransactionUpfrontTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderService $orderService;
    private PriceCalculator $priceCalculator;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->orderService = $container->get(OrderService::class);
        $this->priceCalculator = $container->get(PriceCalculator::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testMatchingFioTransactionCompletesUpfrontOrderIntoOneTimeContract(): void
    {
        $order = $this->createSignedUpfrontOrder();
        $variableSymbol = $order->variableSymbol;
        \assert(null !== $variableSymbol);

        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: 'fio-test-upfront-1',
            amount: $order->firstPaymentPrice,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Testovací',
            date: $this->clock->now(),
            comment: null,
        )));

        $this->entityManager->clear();
        $refreshedOrder = $this->entityManager->find(Order::class, $order->id);
        \assert($refreshedOrder instanceof Order);

        self::assertSame(OrderStatus::COMPLETED, $refreshedOrder->status);

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $refreshedOrder)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract);

        self::assertSame(BillingMode::ONE_TIME, $contract->billingMode);
        self::assertSame(PaymentFrequency::ONE_TIME, $contract->paymentFrequency);
        self::assertNull($contract->nextBillingDate, 'upfront contract must have no billing anchor');
        self::assertNull($contract->paidThroughDate);
    }

    private function createSignedUpfrontOrder(): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box', 'place' => $place]);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $startDate->modify('+92 days'); // ~3 months → upfront eligible

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $endDate,
            $now,
            PaymentFrequency::ONE_TIME,
        );

        // firstPaymentPrice must be the whole rental total — the exact sum the
        // MANUAL monthly track would collect for the same window.
        $expectedTotal = $this->priceCalculator
            ->buildPaymentSchedule($order->storage, $startDate, $endDate, PaymentFrequency::MONTHLY)
            ->totalKnownAmount();
        self::assertSame($expectedTotal, $order->firstPaymentPrice);

        // Mirror what OrderAcceptController locks in after acceptance.
        $order->acceptTerms($now);
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::ONE_TIME);
        $order->assignVariableSymbol('7801230001');

        // Flush so the FIO handler's DQL lookup (findByVariableSymbol) sees the
        // order — the dispatch below runs its queries before its own flush.
        $this->entityManager->flush();

        return $order;
    }
}
