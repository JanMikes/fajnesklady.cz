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
 * and the payment-page QR — lives in OrderAcceptControllerTest.).
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

    public function testUpfrontOrderLongerThanTwelveMonthsPaysInYearlyTranches(): void
    {
        // Spec 078 tranches: 15 months → 2 payments. The first tranche (12
        // months) completes the order; the contract keeps a billing anchor on
        // the second tranche, which a later matching bank transfer settles.
        $order = $this->createSignedUpfrontOrder('+15 months');
        $variableSymbol = $order->variableSymbol;
        \assert(null !== $variableSymbol);

        // firstPaymentPrice is only the FIRST tranche: 12 full months.
        $displaySchedule = $this->priceCalculator->buildScheduleFromOrder($order);
        self::assertCount(2, $displaySchedule->entries);
        self::assertSame($displaySchedule->entries[0]->amount, $order->firstPaymentPrice);

        // 1) First tranche arrives → order completes into an anchored contract.
        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: 'fio-test-upfront-tranche-1',
            amount: $order->firstPaymentPrice,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Testovací',
            date: $this->clock->now(),
            comment: null,
        )));

        $this->entityManager->clear();
        $contract = $this->loadContractByOrderId($order->id);

        self::assertSame(BillingMode::ONE_TIME, $contract->billingMode);
        self::assertSame(PaymentFrequency::ONE_TIME, $contract->paymentFrequency);
        self::assertNotNull($contract->nextBillingDate, 'second tranche must be anchored');
        self::assertSame(
            $displaySchedule->entries[1]->chargeDate->format('Y-m-d'),
            $contract->nextBillingDate->format('Y-m-d'),
        );
        self::assertNotNull($contract->paidThroughDate);
        self::assertSame(
            $displaySchedule->entries[1]->chargeDate->format('Y-m-d'),
            $contract->paidThroughDate->format('Y-m-d'),
        );

        // Mid-rental price-list edit must NOT shift the outstanding tranche —
        // tranches bill at the LOCKED order rate (firstPaymentPrice / 12).
        $contract->storage->updatePrices(null, null, $contract->getEffectiveMonthlyAmount() + 50000, null, $this->clock->now());
        $this->entityManager->flush();
        $this->entityManager->clear();
        $contract = $this->loadContractByOrderId($order->id);

        // 2) Second (final) tranche arrives: 3 remaining months at the locked rate.
        $secondTrancheAmount = 3 * $order->getUpfrontLockedMonthlyRate();
        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: 'fio-test-upfront-tranche-2',
            amount: $secondTrancheAmount,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Testovací',
            date: $this->clock->now(),
            comment: null,
        )));

        $this->entityManager->clear();
        $settled = $this->loadContractByOrderId($order->id);

        self::assertNull($settled->nextBillingDate, 'no tranche remains after the final payment');
        self::assertNotNull($settled->paidThroughDate);
        self::assertNotNull($settled->endDate);
        self::assertSame(
            $settled->endDate->format('Y-m-d'),
            $settled->paidThroughDate->format('Y-m-d'),
            'final tranche pays the rental through its end date',
        );
        self::assertNotNull($settled->lastBilledAt);

        // Each paid tranche issues an invoice via the recurring-charge path.
        $trancheInvoiceCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(\App\Entity\Invoice::class, 'i')
            ->where('i.order = :order')
            ->andWhere('i.amount = :amount')
            ->setParameter('order', $this->entityManager->find(Order::class, $order->id))
            ->setParameter('amount', $secondTrancheAmount)
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(1, $trancheInvoiceCount, 'the paid tranche must produce a Fakturoid invoice');
    }

    private function loadContractByOrderId(\Symfony\Component\Uid\Uuid $orderId): Contract
    {
        $refreshedOrder = $this->entityManager->find(Order::class, $orderId);
        \assert($refreshedOrder instanceof Order);

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $refreshedOrder)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract);

        return $contract;
    }

    private function createSignedUpfrontOrder(string $duration = '+92 days'): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box', 'place' => $place]);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $startDate->modify($duration);

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $endDate,
            $now,
            PaymentFrequency::ONE_TIME,
        );

        // firstPaymentPrice must be the first tranche of the monthly schedule —
        // for ≤ 12 months that is the whole rental total, for longer rentals
        // the first 12 monthly payments (spec 078 tranches).
        $monthlyEntries = $this->priceCalculator
            ->buildPaymentSchedule($order->storage, $startDate, $endDate, PaymentFrequency::MONTHLY)
            ->entries;
        $expectedFirstTranche = array_sum(array_map(
            static fn ($entry) => $entry->amount,
            \array_slice($monthlyEntries, 0, PriceCalculator::MONTHS_PER_UPFRONT_TRANCHE),
        ));
        self::assertSame($expectedFirstTranche, $order->firstPaymentPrice);

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
