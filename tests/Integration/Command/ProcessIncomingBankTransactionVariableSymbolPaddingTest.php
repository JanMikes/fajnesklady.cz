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
 * Spec 090 regression: banks treat the variable symbol as a number and strip
 * leading zeros, so a symbol we stored as "0451060965" arrives from FIO as
 * "451060965". Exact string equality missed it and the money sat unmatched —
 * a real 3 100 Kč incident on 2026-07-21. Both directions must now match.
 */
final class ProcessIncomingBankTransactionVariableSymbolPaddingTest extends KernelTestCase
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

    public function testStoredPaddedSymbolMatchesUnpaddedIncoming(): void
    {
        // The exact shape of the live incident: stored 0451060965, bank sent 451060965.
        $order = $this->createSignedOrder('0451060965');

        $this->dispatchTransfer('fio-vs-pad-1', $order->firstPaymentPrice, '451060965');

        $this->assertPairedTo($order, 'fio-vs-pad-1');
    }

    public function testStoredUnpaddedSymbolMatchesPaddedIncoming(): void
    {
        $order = $this->createSignedOrder('451060965');

        $this->dispatchTransfer('fio-vs-pad-2', $order->firstPaymentPrice, '0451060965');

        $this->assertPairedTo($order, 'fio-vs-pad-2');
    }

    public function testExactSymbolStillMatches(): void
    {
        $order = $this->createSignedOrder('7801230009');

        $this->dispatchTransfer('fio-vs-pad-3', $order->firstPaymentPrice, '7801230009');

        $this->assertPairedTo($order, 'fio-vs-pad-3');
    }

    public function testAllZeroSymbolMatchesNothing(): void
    {
        $this->createSignedOrder('0000000001');

        $this->dispatchTransfer('fio-vs-pad-4', 150_000, '0000000000');

        $tx = $this->findTransaction('fio-vs-pad-4');
        $this->assertNull($tx->pairedOrder, 'An all-zero symbol normalises to nothing and must not match.');
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

    private function assertPairedTo(Order $order, string $fioId): void
    {
        $this->entityManager->clear();

        $tx = $this->findTransaction($fioId);

        $this->assertNotNull($tx->pairedOrder, 'Transfer was not paired to any order.');
        $this->assertTrue(
            $tx->pairedOrder->id->equals($order->id),
            'Transfer paired to the wrong order.',
        );
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

    private function createSignedOrder(string $variableSymbol): Order
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
            $startDate->modify('+92 days'),
            $now,
            PaymentFrequency::ONE_TIME,
        );

        $order->acceptTerms($now);
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::ONE_TIME);
        // Overwrite the creation-assigned symbol with the padded/unpadded shape
        // under test — legacy rows carry exactly these values.
        $order->assignVariableSymbol($variableSymbol);

        // Flush so the handler's DQL lookup sees the order; the dispatch below
        // runs its queries before its own flush.
        $this->entityManager->flush();

        return $order;
    }
}
