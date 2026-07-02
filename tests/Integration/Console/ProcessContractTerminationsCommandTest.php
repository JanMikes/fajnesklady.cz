<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 076 hard stop: contracts never auto-extend, so a card contract that
 * reaches its endDate with a LIVE GoPay token must be terminated by the cron
 * and its token voided.
 */
class ProcessContractTerminationsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();

        $this->application = new Application(self::$kernel);
    }

    public function testCardContractPastEndDateWithLiveTokenIsTerminatedAndTokenVoided(): void
    {
        $now = $this->clock->now();
        $contract = $this->createSettledCardContract(
            startDate: $now->modify('-90 days'),
            endDate: $now->modify('-1 day'),
            parentPaymentId: 'gp_parent_hard_stop',
        );

        $tester = new CommandTester($this->application->find('app:process-contract-terminations'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager->refresh($contract);

        self::assertTrue($contract->isTerminated());
        self::assertNull($contract->goPayParentPaymentId);
        self::assertTrue($this->goPayClient->wasRecurrenceVoided('gp_parent_hard_stop'));
    }

    public function testCardContractStillWithinTermIsLeftAlone(): void
    {
        $now = $this->clock->now();
        $contract = $this->createSettledCardContract(
            startDate: $now->modify('-30 days'),
            endDate: $now->modify('+60 days'),
            parentPaymentId: 'gp_parent_running',
        );

        $tester = new CommandTester($this->application->find('app:process-contract-terminations'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->entityManager->refresh($contract);

        self::assertFalse($contract->isTerminated());
        self::assertFalse($this->goPayClient->wasRecurrenceVoided('gp_parent_running'));
    }

    /**
     * Fully settled card contract: final (prorated) cycle already charged, so
     * nextBillingDate is null and paidThroughDate landed on endDate.
     */
    private function createSettledCardContract(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        string $parentPaymentId,
    ): Contract {
        $user = $this->findUser('user@example.com');
        $storage = $this->findFreeStorage();

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 120000,
            expiresAt: $startDate->modify('+7 days'),
            createdAt: $startDate,
        );
        $order->markPaid($startDate);
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $startDate,
        );
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->sign($startDate);
        $contract->setRecurringPayment($parentPaymentId, null, $endDate);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $this->goPayClient->seedRecurrenceStatus($parentPaymentId, 'PAID', $parentPaymentId, 120000);

        // Guard against setup drift silently voiding the assertions below.
        self::assertTrue($contract->hasAvailabilityGuarantee());
        self::assertSame(OrderStatus::PAID, $order->status);

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

    private function findFreeStorage(): Storage
    {
        // A1 (Praha Centrum, Small) has no fixture rental — safe to book.
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :number')
            ->setParameter('number', 'A1')
            ->getQuery()
            ->getOneOrNullResult();
        \assert($storage instanceof Storage);

        return $storage;
    }
}
