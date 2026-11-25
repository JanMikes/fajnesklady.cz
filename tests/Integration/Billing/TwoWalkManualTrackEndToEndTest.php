<?php

declare(strict_types=1);

namespace App\Tests\Integration\Billing;

use App\Command\CompleteOrderCommand;
use App\Command\ProcessIncomingBankTransactionCommand;
use App\Command\RequestTerminationNoticeCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use App\Value\FioBankTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * The incident's payment type end to end on two-walk billing (2026-11-25):
 * a bank-transfer rental paid BEFORE its start, through the real payment-
 * request cron, FIO pairing and e-mails, to the very last (prorated) cycle.
 */
final class TwoWalkManualTrackEndToEndTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private MockClock $clock;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $clock = $container->get(\Psr\Clock\ClockInterface::class);
        \assert($clock instanceof MockClock);
        $this->clock = $clock; // 2025-06-15 12:00 UTC
        $this->application = new Application(self::$kernel);
    }

    /**
     * Paid 15.06 for a 01.07 – 15.09 rental: periods 01.07, 01.08, 01.09–15.09
     * (quoted schedule), each later one due on the 15th of the month before.
     */
    public function testBankTransferRentalPaidBeforeStartIsRequestedOnTheFirstPaymentDayToTheEnd(): void
    {
        $order = $this->createPaidBankTransferOrder(new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2025-09-15'));
        $schedule = static::getContainer()->get(PriceCalculator::class)->buildScheduleFromOrder($order);
        self::assertCount(3, $schedule->entries);
        $contractId = $this->completeOrder($order);

        // --- Cycle 2: period 01.08 – 01.09, due 15.07
        $this->clock->modify('2025-07-08 07:00');
        $this->runCron();
        $this->runCron(); // idempotent: the same cycle is never requested twice
        $requests = $this->requests($contractId);
        self::assertCount(1, $requests);
        self::assertSame('2025-07-15', $requests[0]->periodStart->format('Y-m-d'), 'keyed on the due date');
        self::assertSame('2025-08-01', $requests[0]->rentalPeriodStart()->format('Y-m-d'));
        self::assertSame('2025-09-01', $requests[0]->periodEnd->format('Y-m-d'));
        self::assertSame($schedule->entries[1]->amount, $requests[0]->amount);
        $html = $this->renderedEmailHtml();
        self::assertStringContainsString('01.08.2025 – 01.09.2025', $html, 'Období = the rental period');
        self::assertStringContainsString('15.07.2025', $html, 'Datum splatnosti = the due date');

        $this->clock->modify('2025-07-15 10:00');
        $this->payByBankTransfer($order, $requests[0]->amount, 'fio-two-walk-1');

        $contract = $this->contract($contractId);
        self::assertSame('2025-08-15', $contract->nextBillingDate?->format('Y-m-d'));
        self::assertSame('2025-09-01', $contract->nextPeriodStart()?->format('Y-m-d'));
        self::assertTrue($this->requests($contractId)[0]->isPaid());

        // --- Cycle 3 (last, prorated): period 01.09 – 15.09, due 15.08
        $this->clock->modify('2025-08-08 07:00');
        $this->runCron();
        $requests = $this->requests($contractId);
        self::assertCount(2, $requests);
        self::assertSame('2025-08-15', $requests[1]->periodStart->format('Y-m-d'));
        self::assertSame('2025-09-01', $requests[1]->rentalPeriodStart()->format('Y-m-d'));
        self::assertSame('2025-09-15', $requests[1]->periodEnd->format('Y-m-d'));
        self::assertSame($schedule->entries[2]->amount, $requests[1]->amount, 'the last cycle pays only the days left');

        $this->clock->modify('2025-08-15 10:00');
        $this->payByBankTransfer($order, $requests[1]->amount, 'fio-two-walk-2');

        $contract = $this->contract($contractId);
        self::assertNull($contract->nextBillingDate, 'nothing left to bill');
        self::assertSame('2025-09-15', $contract->paidThroughDate?->format('Y-m-d'));

        $this->clock->modify('2025-09-08 07:00');
        $this->runCron();
        self::assertCount(2, $this->requests($contractId), 'no request beyond the schedule');
    }

    /**
     * Paid 15.06 for a 13.07 start: the period from 13.08 is due 15.07. A
     * notice given 10.07 ends the rental 10.08 — before that period starts:
     * it is never billed and its pending request is cancelled.
     */
    public function testNoticeEndingBeforeThePrepaidPeriodCancelsItsRequest(): void
    {
        $order = $this->createPaidBankTransferOrder(new \DateTimeImmutable('2025-07-13'), new \DateTimeImmutable('2026-01-13'));
        $contractId = $this->completeOrder($order);

        $this->clock->modify('2025-07-08 07:00');
        $this->runCron();
        $requests = $this->requests($contractId);
        self::assertCount(1, $requests);
        self::assertSame('2025-07-15', $requests[0]->periodStart->format('Y-m-d'));
        self::assertSame('2025-08-13', $requests[0]->rentalPeriodStart()->format('Y-m-d'));

        $this->clock->modify('2025-07-10 09:00');
        $this->commandBus->dispatch(new RequestTerminationNoticeCommand($this->contract($contractId)));

        $contract = $this->contract($contractId);
        self::assertNull($contract->nextBillingDate, 'the period from 13.08 lies after the 10.08 end');
        self::assertSame(ManualPaymentRequest::STATUS_CANCELLED, $this->requests($contractId)[0]->status);
    }

    private function createPaidBankTransferOrder(\DateTimeImmutable $start, \DateTimeImmutable $end): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        $orderService = static::getContainer()->get(OrderService::class);

        $now = $this->clock->now();
        $order = $orderService->createOrder(
            user: $tenant,
            storageType: $storageType,
            place: $place,
            startDate: $start,
            endDate: $end,
            now: $now,
            paymentFrequency: PaymentFrequency::MONTHLY,
        );
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->reserve($now);
        $orderService->processPayment($order);
        $orderService->confirmPayment($order, $now);

        return $order;
    }

    private function completeOrder(Order $order): Uuid
    {
        $envelope = $this->commandBus->dispatch(new CompleteOrderCommand($order));
        $contract = $envelope->last(HandledStamp::class)?->getResult();
        \assert($contract instanceof Contract);
        $this->entityManager->clear();

        return $contract->id;
    }

    private function payByBankTransfer(Order $order, int $amount, string $fioId): void
    {
        $variableSymbol = $this->entityManager->find(Order::class, $order->id)?->variableSymbol;
        self::assertNotNull($variableSymbol);

        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: $fioId,
            amount: $amount,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '1583974193/0800',
            senderName: 'Jan Testovací',
            date: $this->clock->now(),
            comment: null,
        )));
        $this->entityManager->clear();
    }

    private function runCron(): void
    {
        $tester = new CommandTester($this->application->find('app:send-manual-billing-payment-requests'));
        self::assertSame(0, $tester->execute([]));
        $this->entityManager->clear();
    }

    private function contract(Uuid $id): Contract
    {
        $contract = $this->entityManager->find(Contract::class, $id);
        \assert($contract instanceof Contract);

        return $contract;
    }

    /**
     * @return list<ManualPaymentRequest>
     */
    private function requests(Uuid $contractId): array
    {
        /** @var list<ManualPaymentRequest> $requests */
        $requests = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->where('r.contract = :contract')
            ->setParameter('contract', $contractId)
            ->orderBy('r.periodStart', 'ASC')
            ->getQuery()
            ->getResult();

        return $requests;
    }

    private function renderedEmailHtml(): string
    {
        $transport = static::getContainer()->get('test.messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $renderer = new BodyRenderer(static::getContainer()->get('test.twig'));

        $html = '';
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof SendEmailMessage) {
                continue;
            }
            $email = $message->getMessage();
            if ($email instanceof TemplatedEmail && str_starts_with((string) $email->getHtmlTemplate(), 'email/manual_billing_payment')) {
                $renderer->render($email);
                $html .= (string) $email->getHtmlBody();
            }
        }
        self::assertNotSame('', $html, 'a payment-request e-mail was sent');

        return $html;
    }
}
