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
use App\Enum\StorageStatus;
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
use Twig\Environment;

/**
 * Spec 078: the VOP čl. XI sweep — every active non-free contract whose
 * nextBillingDate is ≥ N days in the past is terminated for payment default,
 * regardless of payment track (card, manual bank transfer, lapsed external
 * prepayment).
 */
class TerminateOverdueContractsCommandTest extends KernelTestCase
{
    private const string TENANT_SUBJECT = 'Smlouva ukončena z důvodu neuhrazení platby - Fajnesklady.cz';

    private EntityManagerInterface $entityManager;
    private Application $application;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;
    private Environment $twig;

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

        /** @var Environment $twig */
        $twig = $container->get('test.twig');
        $this->twig = $twig;

        $this->application = new Application(self::$kernel);

        // Wipe fixture contracts (one of them is 9 days overdue by design) so
        // each test controls the full candidate set.
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

    public function testManualContractOverduePastLimitIsTerminatedWithDebtAndEmails(): void
    {
        $contract = $this->createOverdueContract('sweep-manual', dueDaysAgo: 8);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $this->entityManager->flush();

        $tester = $this->runSweep();

        $this->assertStringContainsString('Found 1 contracts overdue more than 7 days.', $tester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isTerminated());
        self::assertSame(TerminationReason::PAYMENT_FAILURE, $reloaded->terminationReason);
        self::assertNotNull($reloaded->outstandingDebtAmount);
        self::assertGreaterThan(0, $reloaded->outstandingDebtAmount);
        self::assertNotNull($reloaded->terminatedAt);
        self::assertSame(StorageStatus::AVAILABLE, $reloaded->storage->status);

        // Customer email: VOP čl. XI citation, non-card wording, čl. X vacating notice.
        $tenantEmails = $this->emailsTo('sweep-manual@test.com', self::TENANT_SUBJECT);
        self::assertCount(1, $tenantEmails);
        $tenantBody = $this->renderHtmlBody($tenantEmails[0]);
        $this->assertStringContainsString('čl. XI Všeobecných obchodních podmínek', $tenantBody);
        $this->assertStringContainsString('Platba za aktuální období nebyla ani po splatnosti připsána na náš účet.', $tenantBody);
        $this->assertStringNotContainsString('stržení platby z Vaší karty', $tenantBody);
        $this->assertStringContainsString('Upozornění dle čl. X VOP', $tenantBody);
        $this->assertStringContainsString('vyklidit do 15 kalendářních dnů', $tenantBody);

        // Every admin gets the DLUH email with the payment-method row.
        $adminEmails = $this->emailsTo('admin@example.com', 'DLUH: Smlouva ukončena');
        self::assertCount(1, $adminEmails);
        $adminBody = $this->renderHtmlBody($adminEmails[0]);
        $this->assertStringContainsString('Způsob platby', $adminBody);
        $this->assertStringContainsString('Bankovní převod / externí', $adminBody);
    }

    public function testContractFiveDaysOverdueIsUntouched(): void
    {
        $contract = $this->createOverdueContract('sweep-fresh', dueDaysAgo: 5);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $this->entityManager->flush();

        $tester = $this->runSweep();

        $this->assertStringContainsString('No contracts overdue more than 7 days.', $tester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isTerminated());
    }

    public function testFreeContractIsNeverTerminated(): void
    {
        $now = $this->clock->now();
        $contract = $this->createOverdueContract('sweep-free', dueDaysAgo: 30);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyIndividualMonthlyAmount(0, null, 'Free contract (test)', $now);
        $contract->popEvents();
        $this->entityManager->flush();

        $tester = $this->runSweep();

        $this->assertStringContainsString('No contracts overdue more than 7 days.', $tester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isTerminated());
    }

    public function testOneTimeContractWithoutBillingAnchorIsNeverACandidate(): void
    {
        $contract = $this->createContract('sweep-onetime');
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        // nextBillingDate stays NULL — paid upfront.
        $this->entityManager->flush();

        $tester = $this->runSweep();

        $this->assertStringContainsString('No contracts overdue more than 7 days.', $tester->getDisplay());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isTerminated());
    }

    public function testUpfrontContractWithUnpaidTrancheIsSwept(): void
    {
        // Spec 078 tranches: a > 12-month upfront contract with an unpaid
        // yearly tranche keeps a live nextBillingDate — N days po splatnosti
        // it is legitimately terminated, unlike ≤ 12-month upfront contracts
        // (anchor NULL, see testOneTimeContractWithoutBillingAnchorIsNeverACandidate).
        $contract = $this->createOverdueContract('sweep-upfront', dueDaysAgo: 8);
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        $contract->applyPaymentFrequency(PaymentFrequency::ONE_TIME);
        $this->entityManager->flush();

        $this->runSweep();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isTerminated());
        self::assertSame(TerminationReason::PAYMENT_FAILURE, $reloaded->terminationReason);
    }

    public function testCardContractIsTerminatedAndRecurrenceVoided(): void
    {
        $now = $this->clock->now();
        $contract = $this->createContract('sweep-card');
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->setRecurringPayment(
            'gp_parent_sweep',
            $now->modify('-8 days')->setTime(0, 0),
            $now->modify('-8 days')->setTime(0, 0),
        );
        $this->entityManager->flush();
        $this->goPayClient->seedRecurrenceStatus('gp_parent_sweep', 'PAID', 'gp_parent_sweep', 120000);

        $this->runSweep();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isTerminated());
        self::assertSame(TerminationReason::PAYMENT_FAILURE, $reloaded->terminationReason);
        self::assertNull($reloaded->goPayParentPaymentId);
        self::assertTrue($this->goPayClient->wasRecurrenceVoided('gp_parent_sweep'));

        $adminEmails = $this->emailsTo('admin@example.com', 'DLUH: Smlouva ukončena');
        self::assertCount(1, $adminEmails);
        $this->assertStringContainsString('Karta (GoPay)', $this->renderHtmlBody($adminEmails[0]));
    }

    public function testLapsedExternallyPrepaidContractIsTerminated(): void
    {
        $now = $this->clock->now();
        $contract = $this->createContract('sweep-prepaid');
        // paidThroughDate 9 days past → nextBillingDate 8 days past.
        $contract->markExternallyPrepaid($now->modify('-9 days')->setTime(0, 0));
        $this->entityManager->flush();

        $this->runSweep();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isTerminated());
        self::assertSame(TerminationReason::PAYMENT_FAILURE, $reloaded->terminationReason);
        self::assertNotNull($reloaded->outstandingDebtAmount);
        self::assertGreaterThan(0, $reloaded->outstandingDebtAmount);
    }

    public function testConfiguredDaysAreHonored(): void
    {
        $now = $this->clock->now();

        /** @var PlatformSettingsRepository $settingsRepository */
        $settingsRepository = static::getContainer()->get(PlatformSettingsRepository::class);
        $settingsRepository->getSettings()->updateOverdueTerminationDays(14, $now);

        $eightDays = $this->createOverdueContract('sweep-cfg-8', dueDaysAgo: 8);
        $eightDays->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $fifteenDays = $this->createOverdueContract('sweep-cfg-15', dueDaysAgo: 15);
        $fifteenDays->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $this->entityManager->flush();

        $tester = $this->runSweep();

        $this->assertStringContainsString('Found 1 contracts overdue more than 14 days.', $tester->getDisplay());

        $this->entityManager->clear();
        $reloadedEight = $this->entityManager->find(Contract::class, $eightDays->id);
        $reloadedFifteen = $this->entityManager->find(Contract::class, $fifteenDays->id);
        self::assertNotNull($reloadedEight);
        self::assertNotNull($reloadedFifteen);
        self::assertFalse($reloadedEight->isTerminated());
        self::assertTrue($reloadedFifteen->isTerminated());
    }

    private function runSweep(): CommandTester
    {
        $tester = new CommandTester($this->application->find('app:terminate-overdue-contracts'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /**
     * Contract whose unpaid billing cycle was due $dueDaysAgo days ago —
     * nextBillingDate and paidThroughDate anchored at midnight like the
     * production billing crons leave them.
     */
    private function createOverdueContract(string $seed, int $dueDaysAgo): Contract
    {
        $dueDate = $this->clock->now()->modify(sprintf('-%d days', $dueDaysAgo))->setTime(0, 0);

        $contract = $this->createContract($seed);
        $contract->scheduleNextBilling($dueDate, $dueDate);

        return $contract;
    }

    private function createContract(string $seed): Contract
    {
        $now = $this->clock->now();

        $user = new User(Uuid::v7(), $seed.'@test.com', 'password', 'Sweep', 'Tester '.$seed, $now);
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
            name: 'Sweep Box',
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
        $contract->sign($startDate);
        $this->entityManager->persist($contract);

        return $contract;
    }

    /**
     * @return list<TemplatedEmail>
     */
    private function emailsTo(string $address, string $subjectContains): array
    {
        $matches = [];
        foreach ($this->sentEmails as $email) {
            if (!$email instanceof TemplatedEmail) {
                continue;
            }
            if (!str_contains((string) $email->getSubject(), $subjectContains)) {
                continue;
            }
            foreach ($email->getTo() as $to) {
                if ($to->getAddress() === $address) {
                    $matches[] = $email;
                }
            }
        }

        return $matches;
    }

    private function renderHtmlBody(TemplatedEmail $email): string
    {
        $template = $email->getHtmlTemplate();
        \assert(null !== $template);

        return $this->twig->render($template, $email->getContext());
    }
}
