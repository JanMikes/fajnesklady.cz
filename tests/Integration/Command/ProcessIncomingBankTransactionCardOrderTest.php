<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessIncomingBankTransactionCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\OrderService;
use App\Value\FioBankTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A card (AUTO_RECURRING) order establishes its recurring mandate by paying the
 * FIRST charge with a card — there is no other way to get the token. Spec 089
 * gave every order a variable symbol and shows card customers bank details on the
 * debt page, which made it possible to wire the first payment instead. Doing so
 * would complete the order into a contract that can never charge. The matcher
 * must decline and leave the money for an admin.
 */
final class ProcessIncomingBankTransactionCardOrderTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderService $orderService;
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
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testWireDoesNotCompleteAutoRecurringCardOrder(): void
    {
        $order = $this->createCardOrder();
        $variableSymbol = $order->variableSymbol;
        \assert(null !== $variableSymbol);

        $this->dispatchTransfer('fio-card-1', $order->firstPaymentPrice, $variableSymbol);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Order::class, $order->id);
        \assert($refreshed instanceof Order);

        $this->assertNotSame(
            OrderStatus::COMPLETED,
            $refreshed->status,
            'A wire must never complete a card order — no recurring token would exist.',
        );
        $this->assertNull($refreshed->paidAt);
    }

    public function testDeclinedTransferIsLeftUnmatchedForAnAdmin(): void
    {
        $order = $this->createCardOrder();
        $variableSymbol = $order->variableSymbol;
        \assert(null !== $variableSymbol);

        $this->dispatchTransfer('fio-card-2', $order->firstPaymentPrice, $variableSymbol);

        $this->entityManager->clear();
        $tx = $this->findTransaction('fio-card-2');

        $this->assertTrue($tx->isUnmatched(), 'The transfer must stay unmatched so an admin can resolve it.');
        $this->assertNull($tx->pairedOrder);
    }

    /**
     * The debt branch must keep working for card orders — that is the entire
     * point of spec 089 and must not be caught by the first-payment guard.
     */
    public function testDebtOnACardOrderIsStillSettledByWire(): void
    {
        $order = $this->createCardOrder();
        $order->setOnboardingDebt(350_000);
        $this->entityManager->flush();

        $variableSymbol = $order->variableSymbol;
        \assert(null !== $variableSymbol);

        $this->dispatchTransfer('fio-card-3', 350_000, $variableSymbol);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Order::class, $order->id);
        \assert($refreshed instanceof Order);

        $this->assertFalse($refreshed->hasUnpaidDebt(), 'A card order must still be able to settle its debt by wire.');
        $this->assertNotNull($refreshed->debtPaidAt);
    }

    private function dispatchTransfer(string $fioId, int $amount, string $variableSymbol): void
    {
        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: $fioId,
            amount: $amount,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Testovací',
            date: $this->clock->now(),
            comment: null,
        )));
    }

    private function findTransaction(string $fioId): BankTransaction
    {
        $tx = $this->entityManager->createQueryBuilder()
            ->select('bt')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.fioTransactionId = :fioId')
            ->setParameter('fioId', $fioId)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($tx instanceof BankTransaction, 'Bank transaction was not ingested at all.');

        return $tx;
    }

    private function createCardOrder(): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box', 'place' => $place]);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $startDate->modify('+6 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );

        $order->acceptTerms($now);
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $order->setBillingMode(BillingMode::AUTO_RECURRING);

        // Flush so the handler's DQL lookup sees the order.
        $this->entityManager->flush();

        return $order;
    }
}
