<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\TerminationReason;
use App\Repository\PlatformSettingsRepository;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 078: app:retry-failed-payments only retries — termination for payment
 * default is owned by the app:terminate-overdue-contracts sweep, and the
 * "Výzva k úhradě" deadline follows the configurable overdueTerminationDays.
 */
class RetryFailedPaymentsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;

    /** @var list<Email> */
    private array $sentEmails = [];

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

        // Wipe fixture contracts so each test controls the retry candidate set.
        $this->entityManager->createQueryBuilder()
            ->delete(Contract::class, 'c')
            ->getQuery()
            ->execute();

        $dispatcher = $container->get('event_dispatcher');
        $this->sentEmails = [];
        // Register before BodyRenderer so the TemplatedEmail still has its
        // context attached for assertions.
        $dispatcher->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testFinalFailedRetryRecordsAttemptButDoesNotTerminate(): void
    {
        $contract = $this->createCardContractOnFinalRetry('retry-final');
        $this->goPayClient->willFailNextRecurrence();

        $tester = new CommandTester($this->application->find('app:retry-failed-payments'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $this->assertStringContainsString('[NO MORE RETRIES]', $tester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertSame(3, $reloaded->failedBillingAttempts);
        self::assertFalse($reloaded->isTerminated());
        self::assertSame('gp_parent_retry-final', $reloaded->goPayParentPaymentId);
        self::assertFalse($this->goPayClient->wasRecurrenceVoided('gp_parent_retry-final'));

        foreach ($this->sentEmails as $email) {
            $this->assertStringNotContainsString(
                'Smlouva ukončena z důvodu neuhrazení platby',
                (string) $email->getSubject(),
                'The retry cron must not send the payment-default termination email anymore.',
            );
        }
    }

    public function testSweepTerminatesContractTheSameDayAfterFinalFailedRetry(): void
    {
        $contract = $this->createCardContractOnFinalRetry('retry-pair');
        $this->goPayClient->willFailNextRecurrence();

        $retryTester = new CommandTester($this->application->find('app:retry-failed-payments'));
        $retryTester->execute([]);
        $retryTester->assertCommandIsSuccessful();

        // 12:30 sweep, 30 minutes after the 12:00 final retry: same-day termination.
        $sweepTester = new CommandTester($this->application->find('app:terminate-overdue-contracts'));
        $sweepTester->execute([]);
        $sweepTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Found 1 contracts overdue more than 7 days.', $sweepTester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isTerminated());
        self::assertSame(TerminationReason::PAYMENT_FAILURE, $reloaded->terminationReason);
        self::assertNull($reloaded->goPayParentPaymentId);
        self::assertTrue($this->goPayClient->wasRecurrenceVoided('gp_parent_retry-pair'));
    }

    public function testPaymentDemandDeadlineFollowsConfiguredOverdueDays(): void
    {
        $now = $this->clock->now();

        /** @var PlatformSettingsRepository $settingsRepository */
        $settingsRepository = static::getContainer()->get(PlatformSettingsRepository::class);
        $settingsRepository->getSettings()->updateOverdueTerminationDays(14, $now);
        $this->entityManager->flush();

        // Due 3 days ago, one failed attempt → today's retry is attempt 2,
        // which triggers the formal "Výzva k úhradě".
        $dueDate = $now->modify('-3 days')->setTime(0, 0);
        $contract = $this->createCardContract('retry-demand', $dueDate);
        $contract->recordFailedBillingAttempt($now->modify('-3 days'));
        $this->entityManager->flush();

        $this->goPayClient->willFailNextRecurrence();

        $tester = new CommandTester($this->application->find('app:retry-failed-payments'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $demandEmails = array_values(array_filter(
            $this->sentEmails,
            static fn (Email $email): bool => str_contains((string) $email->getSubject(), 'Výzva k úhradě nájemného'),
        ));
        self::assertCount(1, $demandEmails);
        $demandEmail = $demandEmails[0];
        self::assertInstanceOf(TemplatedEmail::class, $demandEmail);

        $deadline = $demandEmail->getContext()['deadline'];
        self::assertInstanceOf(\DateTimeImmutable::class, $deadline);
        // dueDate + configured 14 days, NOT the previously hardcoded now + 4 days.
        self::assertSame('2025-06-26', $deadline->format('Y-m-d'));
    }

    /**
     * Card contract at the final-retry stage: due 7 days ago, 2 failed
     * attempts, second failure 4 days ago — exactly what findNeedingRetry()
     * picks up for the 3rd (last) attempt.
     */
    private function createCardContractOnFinalRetry(string $seed): Contract
    {
        $now = $this->clock->now();
        $dueDate = $now->modify('-7 days')->setTime(0, 0);

        $contract = $this->createCardContract($seed, $dueDate);
        $contract->recordFailedBillingAttempt($now->modify('-7 days'));
        $contract->recordFailedBillingAttempt($now->modify('-4 days'));
        $contract->recordPaymentDemandSent($now->modify('-4 days'));
        $this->entityManager->flush();

        return $contract;
    }

    private function createCardContract(string $seed, \DateTimeImmutable $dueDate): Contract
    {
        $now = $this->clock->now();

        $user = new User(Uuid::v7(), $seed.'@test.com', 'password', 'Retry', 'Tester '.$seed, $now);
        $user->popEvents();
        $this->entityManager->persist($user);

        $place = new Place(
            id: Uuid::v7(),
            name: $seed.' place',
            address: 'Testovací 123',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $this->entityManager->persist($place);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Retry Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $now,
        );
        $this->entityManager->persist($storageType);

        $storage = new Storage(
            id: Uuid::v7(),
            number: strtoupper(substr($seed, -4)),
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $storage->occupy($now);
        $this->entityManager->persist($storage);

        $startDate = $now->modify('-90 days');
        $endDate = $startDate->modify('+12 months');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('-83 days'),
            createdAt: $startDate,
        );
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
        $contract->setRecurringPayment('gp_parent_'.$seed, $dueDate, $dueDate);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $this->goPayClient->seedRecurrenceStatus('gp_parent_'.$seed, 'PAID', 'gp_parent_'.$seed, 35000);

        return $contract;
    }
}
