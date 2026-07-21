<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\InitiateDebtPaymentCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\AllocationStepType;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\StorageStatus;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 089 made both payment methods available on every order, so a customer can
 * wire part of the debt and then finish by card. The card charge must be for what
 * is actually outstanding — charging the full debt would overcharge them.
 */
final class InitiateDebtPaymentHandlerTest extends KernelTestCase
{
    private const int DEBT_IN_HALER = 350_000;

    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private MockGoPayClient $goPayClient;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->commandBus = static::getContainer()->get('test.command.bus');

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = static::getContainer()->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testChargesFullDebtWhenNothingWiredYet(): void
    {
        $order = $this->createDebtOrder();

        $paymentId = $this->initiate($order);

        $this->assertSame(self::DEBT_IN_HALER, $this->goPayClient->getStatus($paymentId)->amount);
    }

    public function testChargesOnlyTheRemainderAfterAPartialWire(): void
    {
        $order = $this->createDebtOrder();
        $this->recordDebtAllocation($order, 100_000);

        $paymentId = $this->initiate($order);

        $this->assertSame(
            250_000,
            $this->goPayClient->getStatus($paymentId)->amount,
            'A partially wired debt must not be charged in full by card.',
        );
    }

    private function initiate(Order $order): string
    {
        $envelope = $this->commandBus->dispatch(new InitiateDebtPaymentCommand(
            $order,
            'https://example.test/navrat',
            'https://example.test/notifikace',
        ));

        $handled = $envelope->last(HandledStamp::class);
        \assert(null !== $handled);
        $payment = $handled->getResult();

        return $payment->id;
    }

    private function createDebtOrder(): Order
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $order = new Order(
            id: Uuid::v7(),
            user: $this->findTenant(),
            storage: $this->findAvailableStorage(),
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+1 day'),
            endDate: $now->modify('+6 months'),
            firstPaymentPrice: 150_000,
            expiresAt: $now->modify('+30 days'),
            createdAt: $now,
        );
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $order->assignVariableSymbol((string) random_int(1_000_000_000, 9_999_999_999));
        $order->setOnboardingDebt(self::DEBT_IN_HALER);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Under spec 091 a partial payment is recorded as a typed allocation — that is
     * what makes debt money and first-payment money separate pools.
     */
    private function recordDebtAllocation(Order $order, int $amountInHaler): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $bankTx = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: (string) random_int(1_000_000, 9_999_999),
            amount: $amountInHaler,
            currency: 'CZK',
            variableSymbol: $order->variableSymbol,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Novak',
            transactionDate: $now->modify('-2 hours'),
            comment: null,
            createdAt: $now,
        );
        $this->entityManager->persist($bankTx);

        $this->entityManager->persist(new BankTransactionAllocation(
            id: Uuid::v7(),
            bankTransaction: $bankTx,
            order: $order,
            type: AllocationStepType::ONBOARDING_DEBT,
            amountInHaler: $amountInHaler,
            createdAt: $now,
        ));

        $this->entityManager->flush();
    }

    private function findTenant(): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::TENANT_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($user instanceof User, 'Tenant fixture user not found');

        return $user;
    }

    private function findAvailableStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.status = :status')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage, 'No available storage found in fixtures');

        return $storage;
    }
}
