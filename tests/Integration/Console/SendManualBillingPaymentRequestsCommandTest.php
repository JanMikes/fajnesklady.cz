<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Twig\Environment;

final class SendManualBillingPaymentRequestsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderService $orderService;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();

        $this->application = new Application(self::$kernel);
    }

    public function testInitialStageDispatchedSevenDaysBeforeNextBillingDate(): void
    {
        $contract = $this->createManualContractDueOn('2025-06-22');

        $exitCode = $this->runCron();

        self::assertSame(0, $exitCode);
        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertArrayHasKey(ManualBillingReminderSchedule::STAGE_INITIAL, $request->sentStages);
        self::assertSame('pending', $request->status);

        // Spec 076: manual cycles are bank-transfer only — no per-cycle GoPay
        // link is created; the reminder carries a variable symbol instead.
        self::assertNull($request->goPayPaymentId);
        self::assertNull($request->goPayGatewayUrl);
        self::assertNotNull($request->contract->order->variableSymbol);
        self::assertSame([], $this->goPayClient->getCreatedPayments());
    }

    public function testPaymentGraceSuppressesReminder(): void
    {
        // nextBillingDate = 2025-06-12 → today (2025-06-15) is d+3 = overdue
        // first, which would normally fire — but an admin extension is active.
        $contract = $this->createManualContractDueOn('2025-06-12');
        $contract->extendPaymentDeadline(new \DateTimeImmutable('2025-06-30'), $this->clock->now());
        $this->entityManager->flush();

        $exitCode = $this->runCron();

        self::assertSame(0, $exitCode);
        self::assertNull(
            $this->loadRequestForContract($contract),
            'No payment-request row is created while an extension is in effect.',
        );
    }

    public function testRunningCronTwiceTheSameDayDoesNotResendTheStage(): void
    {
        $contract = $this->createManualContractDueOn('2025-06-22');

        $this->runCron();
        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        $firstSentAt = $request->sentStages[ManualBillingReminderSchedule::STAGE_INITIAL];

        $this->runCron();
        $this->entityManager->clear();
        $secondRequest = $this->loadRequestForContract($contract);
        self::assertNotNull($secondRequest);

        // Same row (unique constraint) + same single-stage map (idempotency gate).
        self::assertSame($request->id->toRfc4122(), $secondRequest->id->toRfc4122());
        self::assertCount(1, $secondRequest->sentStages);
        self::assertSame($firstSentAt, $secondRequest->sentStages[ManualBillingReminderSchedule::STAGE_INITIAL]);
    }

    public function testCronSkipsWhenRequestIsAlreadyPaid(): void
    {
        $contract = $this->createManualContractDueOn('2025-06-22');

        // First run: sends d-7 reminder.
        $this->runCron();
        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);

        // Simulate the customer paying the link before the d-2 reminder is due.
        $this->entityManager->createQueryBuilder()
            ->update(ManualPaymentRequest::class, 'r')
            ->set('r.status', ':paid')
            ->where('r.id = :id')
            ->setParameter('paid', ManualPaymentRequest::STATUS_PAID)
            ->setParameter('id', $request->id)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();

        // Re-running at d-7 again must short-circuit on the isPaid() gate even
        // though the d-7 sent timestamp is also in place. (Belt-and-braces.)
        $this->runCron();
        $reloaded = $this->loadRequestForContract($contract);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->sentStages);
    }

    public function testCronDoesNothingBeforeTheEarliestStageDay(): void
    {
        // nextBillingDate = 2025-06-25 → today (2025-06-15) is d-10, before the
        // initial (-7) offset — the contract is dormant.
        $this->createManualContractDueOn('2025-06-25');

        $this->runCron();

        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ManualPaymentRequest::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertSame(0, $count, 'No ManualPaymentRequest row should be created before the earliest stage day.');
    }

    public function testLateEntryBetweenStageDaysCatchesUpWithTheCurrentBracket(): void
    {
        // nextBillingDate = 2025-06-20 → today (2025-06-15) is d-5, between the
        // initial (-7) and reminder (-2) days. A contract entering the manual
        // track late (late onboarding, billing-mode repair) must still get the
        // initial payment request instead of silently skipping it.
        $contract = $this->createManualContractDueOn('2025-06-20');

        $this->runCron();

        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertSame([ManualBillingReminderSchedule::STAGE_INITIAL], array_keys($request->sentStages));
    }

    public function testLateEntryAfterDueDateSendsExactlyOneCatchUpStage(): void
    {
        // The production incident: an admin-onboarded prepaid contract whose
        // nextBillingDate (2025-06-14) already passed when it entered the
        // manual track. Today (2025-06-15) is d+1 → the final-due stage is the
        // current bracket; initial and reminder are skipped, and only ONE
        // e-mail goes out — never a burst of all missed stages.
        $contract = $this->createManualContractDueOn('2025-06-14');

        $this->runCron();

        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertSame([ManualBillingReminderSchedule::STAGE_FINAL_DUE], array_keys($request->sentStages));
        self::assertNotNull($request->contract->order->variableSymbol);

        // The catch-up e-mail must render (strict_variables) and must NOT
        // claim "splatná dnes" — the due date already passed.
        $email = $this->findRenderedBillingEmail('Platba je po splatnosti — Fajnesklady.cz');
        self::assertNotNull($email);
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('po splatnosti', $html);
        self::assertStringContainsString('14.06.2025', $html);
    }

    public function testLateEntryPastTheOverdueFinalDaySendsOnlyTheOverdueFinalStage(): void
    {
        // nextBillingDate = 2025-06-01 → today (2025-06-15) is d+14, past every
        // offset. Exactly one catch-up e-mail (the last overdue stage) fires so
        // the customer is contacted before the overdue-termination cron acts.
        $contract = $this->createManualContractDueOn('2025-06-01');

        $this->runCron();

        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertSame([ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL], array_keys($request->sentStages));

        // The day count is computed at send time (d+14), not hardcoded "7 dní".
        $email = $this->findRenderedBillingEmail('Poslední upomínka: 14 dní po splatnosti — Fajnesklady.cz');
        self::assertNotNull($email);
        self::assertStringContainsString('14 dní po splatnosti', (string) $email->getHtmlBody());
    }

    /**
     * Render every queued TemplatedEmail through Twig (the in-memory mailer
     * transport defers body rendering, so template errors would otherwise
     * never surface in tests) and return the one with the given subject.
     */
    private function findRenderedBillingEmail(string $subject): ?TemplatedEmail
    {
        $transport = static::getContainer()->get('test.messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        /** @var Environment $twig */
        $twig = static::getContainer()->get('test.twig');
        $renderer = new BodyRenderer($twig);

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof SendEmailMessage) {
                continue;
            }

            $email = $message->getMessage();
            if (!$email instanceof TemplatedEmail || $email->getSubject() !== $subject) {
                continue;
            }

            $renderer->render($email);

            return $email;
        }

        return null;
    }

    public function testOverdueStageIncrementsFailedBillingAttempts(): void
    {
        // nextBillingDate = 2025-06-12 → today (2025-06-15) is d+3 = overdue first.
        $contract = $this->createManualContractDueOn('2025-06-12');
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($refreshed);
        self::assertSame(0, $refreshed->failedBillingAttempts);

        $this->runCron();

        $this->entityManager->clear();
        $afterRun = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($afterRun);
        self::assertSame(1, $afterRun->failedBillingAttempts);

        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertArrayHasKey(ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST, $request->sentStages);
    }

    public function testPerOrderScheduleSnapshotSurvivesPlaceEdits(): void
    {
        $contract = $this->createManualContractDueOn('2025-06-22');

        // Edit the Place schedule — running rentals must keep their snapshot.
        $place = $contract->storage->getPlace();
        $place->updateManualBillingSchedule(-14, -5, 0, 1, 14, $this->clock->now());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->runCron();

        // The Order's snapshot is still [-7, -2, 0, 3, 7], so d-7 (2025-06-15
        // → 2025-06-22) still fires; the new -14 offset would have made it dormant.
        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertArrayHasKey(ManualBillingReminderSchedule::STAGE_INITIAL, $request->sentStages);
    }

    private function runCron(): int
    {
        $tester = new CommandTester($this->application->find('app:send-manual-billing-payment-requests'));

        return $tester->execute([]);
    }

    public function testUpfrontTrancheContractGetsPaymentRequestWithTrancheAmount(): void
    {
        // Spec 078 tranches: a > 12-month upfront contract with an outstanding
        // yearly tranche is picked up by the same cron; the request bills the
        // TRANCHE amount (next up-to-12 months of the monthly walk), not a
        // flat monthly or yearly rate.
        $contract = $this->createUpfrontTrancheContractDueOn('2025-06-22');

        $exitCode = $this->runCron();

        self::assertSame(0, $exitCode);
        $request = $this->loadRequestForContract($contract);
        self::assertNotNull($request);
        self::assertArrayHasKey(ManualBillingReminderSchedule::STAGE_INITIAL, $request->sentStages);
        self::assertSame('pending', $request->status);
        // > 12 months remain past the anchor → full tranche = 12 × monthly rate.
        self::assertSame(12 * $contract->getEffectiveMonthlyAmount(), $request->amount);
        // Tranche period = one +1 year cadence step from the anchor.
        self::assertSame('2026-06-22', $request->periodEnd->format('Y-m-d'));
        // Bank-transfer request: VS present, no GoPay link.
        self::assertNotNull($request->contract->order->variableSymbol);
        self::assertNull($request->goPayPaymentId);
    }

    private function createUpfrontTrancheContractDueOn(string $nextBillingDate): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $now->modify('+1 day'),
            $now->modify('+1 day')->modify('+15 months'),
            $now,
            PaymentFrequency::ONE_TIME,
        );
        $order->setBillingMode(BillingMode::ONE_TIME);
        $order->markPaid($now);
        $contract = $this->orderService->completeOrder($order, $now);

        $this->entityManager->flush();

        // Pin the outstanding-tranche anchor to the requested calendar day so
        // the test can target a specific schedule stage relative to MockClock.
        $this->entityManager->createQueryBuilder()
            ->update(Contract::class, 'c')
            ->set('c.nextBillingDate', ':next')
            ->set('c.paidThroughDate', ':next')
            ->where('c.id = :id')
            ->setParameter('next', new \DateTimeImmutable($nextBillingDate))
            ->setParameter('id', $contract->id)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();

        $refetched = $this->entityManager->find(Contract::class, $contract->id);
        \assert($refetched instanceof Contract);

        return $refetched;
    }

    private function createManualContractDueOn(string $nextBillingDate): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $now->modify('+1 day'),
            $now->modify('+6 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->markPaid($now);
        $contract = $this->orderService->completeOrder($order, $now);

        $this->entityManager->flush();

        // Pin the contract's nextBillingDate to the requested calendar day so
        // the test can target a specific schedule stage relative to MockClock.
        $this->entityManager->createQueryBuilder()
            ->update(Contract::class, 'c')
            ->set('c.nextBillingDate', ':next')
            ->where('c.id = :id')
            ->setParameter('next', new \DateTimeImmutable($nextBillingDate))
            ->setParameter('id', $contract->id)
            ->getQuery()
            ->execute();
        $this->entityManager->clear();

        $refetched = $this->entityManager->find(Contract::class, $contract->id);
        \assert($refetched instanceof Contract);

        return $refetched;
    }

    private function loadRequestForContract(Contract $contract): ?ManualPaymentRequest
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->where('r.contract = :contract')
            ->setParameter('contract', $contract)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
