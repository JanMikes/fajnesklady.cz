<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\StorageAvailabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 076 availability guarantee: a live-token card (AUTO_RECURRING) rental
 * blocks its storage open-endedly — any future window is unavailable — while
 * bank-transfer rentals free the unit the day after their endDate.
 */
class StorageAvailabilityGuaranteeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private StorageAvailabilityChecker $checker;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

        /** @var StorageAvailabilityChecker $checker */
        $checker = $container->get(StorageAvailabilityChecker::class);
        $this->checker = $checker;

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;
    }

    public function testCardContractWithLiveTokenBlocksWindowsYearsAfterItsEnd(): void
    {
        $now = $this->clock->now();
        $storage = $this->findStorage('A1');
        $this->createContract($storage, BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, tokenId: 'gp_guarantee_block', endDate: $now->modify('+60 days'));

        self::assertFalse($this->checker->isAvailable($storage, $now->modify('+2 years'), $now->modify('+2 years +1 month')));
    }

    public function testBankContractFreesTheWindowAfterItsEnd(): void
    {
        $now = $this->clock->now();
        $storage = $this->findStorage('A1');
        $this->createContract($storage, BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER, tokenId: null, endDate: $now->modify('+60 days'));

        self::assertFalse($this->checker->isAvailable($storage, $now->modify('+30 days'), $now->modify('+45 days')));
        self::assertTrue($this->checker->isAvailable($storage, $now->modify('+61 days'), $now->modify('+90 days')));
    }

    public function testCardContractWithCancelledTokenFreesTheWindowAfterItsEnd(): void
    {
        $now = $this->clock->now();
        $storage = $this->findStorage('A1');
        $contract = $this->createContract($storage, BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, tokenId: 'gp_guarantee_cancelled', endDate: $now->modify('+60 days'));
        $contract->cancelRecurringPayment();
        $this->entityManager->flush();

        self::assertTrue($this->checker->isAvailable($storage, $now->modify('+61 days'), $now->modify('+90 days')));
    }

    public function testUnpaidCardOrderAlreadyBlocksOpenEndedly(): void
    {
        $now = $this->clock->now();
        $storage = $this->findStorage('A1');
        $this->createOrder($storage, BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, endDate: $now->modify('+60 days'));

        self::assertFalse($this->checker->isAvailable($storage, $now->modify('+2 years'), $now->modify('+2 years +1 month')));
    }

    public function testUnpaidBankOrderBlocksOnlyItsOwnWindow(): void
    {
        $now = $this->clock->now();
        $storage = $this->findStorage('A1');
        $this->createOrder($storage, BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER, endDate: $now->modify('+60 days'));

        self::assertFalse($this->checker->isAvailable($storage, $now->modify('+30 days'), $now->modify('+45 days')));
        self::assertTrue($this->checker->isAvailable($storage, $now->modify('+61 days'), $now->modify('+90 days')));
    }

    private function createOrder(Storage $storage, BillingMode $billingMode, PaymentMethod $method, \DateTimeImmutable $endDate): Order
    {
        $now = $this->clock->now();
        $order = new Order(
            id: Uuid::v7(),
            user: $this->findUser('user@example.com'),
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+1 day'),
            endDate: $endDate,
            firstPaymentPrice: 120000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->setBillingMode($billingMode);
        $order->setPaymentMethod($method);
        $order->popEvents();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function createContract(Storage $storage, BillingMode $billingMode, PaymentMethod $method, ?string $tokenId, \DateTimeImmutable $endDate): Contract
    {
        $now = $this->clock->now();
        $order = $this->createOrder($storage, $billingMode, $method, $endDate);
        $order->markPaid($now);
        $order->popEvents();

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $storage,
            startDate: $order->startDate,
            endDate: $endDate,
            createdAt: $now,
        );
        $contract->applyBillingMode($billingMode);
        $contract->sign($now);
        if (null !== $tokenId) {
            $contract->setRecurringPayment($tokenId, $now->modify('+1 month'), $now->modify('+1 month'));
        }
        $order->complete($contract->id, $now);
        $order->popEvents();
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $contract;
    }

    private function findUser(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }

    private function findStorage(string $number): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($storage instanceof Storage);

        return $storage;
    }
}
