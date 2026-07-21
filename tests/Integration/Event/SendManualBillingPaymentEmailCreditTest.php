<?php

declare(strict_types=1);

namespace App\Tests\Integration\Event;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Event\ManualBillingPaymentOverdue;
use App\Event\ManualBillingPaymentRequested;
use App\Event\SendManualBillingPaymentOverdueEmailHandler;
use App\Event\SendManualBillingPaymentRequestedEmailHandler;
use App\Service\Billing\ManualBillingReminderSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Spec 091 D3: what we ASK the customer for is the cycle amount less any credit
 * the contract is holding — the frozen `ManualPaymentRequest::$amount` stays the
 * full cycle. Both reminder e-mails render `RecurringAmountCalculator::amountToRequest()`,
 * and the QR must carry the exact same figure as the summary table.
 */
class SendManualBillingPaymentEmailCreditTest extends KernelTestCase
{
    private const int MONTHLY_AMOUNT = 310_000; // 3 100 Kč
    private const int CREDIT = 40_000;          // 400 Kč

    private EntityManagerInterface $entityManager;
    private SendManualBillingPaymentRequestedEmailHandler $requestedHandler;
    private SendManualBillingPaymentOverdueEmailHandler $overdueHandler;
    private ClockInterface $clock;
    private Environment $twig;

    /** @var list<Email> */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->requestedHandler = $container->get(SendManualBillingPaymentRequestedEmailHandler::class);
        $this->overdueHandler = $container->get(SendManualBillingPaymentOverdueEmailHandler::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->twig = $container->get('test.twig');

        $this->sentEmails = [];
        $container->get('event_dispatcher')->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($message instanceof Email) {
                $this->sentEmails[] = clone $message;
            }
        }, priority: 1024);
    }

    public function testRequestedEmailWithoutCreditAsksForTheFullCycle(): void
    {
        [$contract, $request] = $this->createManualCycle(credit: 0);

        ($this->requestedHandler)(new ManualBillingPaymentRequested(
            $contract->id,
            $request->id,
            ManualBillingReminderSchedule::STAGE_INITIAL,
            $this->clock->now(),
        ));

        $email = $this->lastTemplatedEmail();
        $context = $email->getContext();
        $this->assertSame('3 100,00', $context['amountInCzk']);
        $this->assertNotNull($context['bankAccount']);
        $this->assertQrCarries($context, 310_000);

        $body = $this->twig->render((string) $email->getHtmlTemplate(), $context);
        $this->assertStringContainsString('3 100,00 Kč vč. DPH', $body);
        $this->assertStringContainsString('Platební údaje pro bankovní převod', $body);
    }

    public function testRequestedEmailSubtractsCreditFromBothTheAmountAndTheQr(): void
    {
        [$contract, $request] = $this->createManualCycle(credit: self::CREDIT);

        ($this->requestedHandler)(new ManualBillingPaymentRequested(
            $contract->id,
            $request->id,
            ManualBillingReminderSchedule::STAGE_INITIAL,
            $this->clock->now(),
        ));

        $email = $this->lastTemplatedEmail();
        $context = $email->getContext();

        // Frozen request row is untouched — this is a render-time subtraction.
        $this->assertSame(self::MONTHLY_AMOUNT, $request->amount);
        $this->assertSame('2 700,00', $context['amountInCzk']);
        $this->assertQrCarries($context, 270_000);

        $body = $this->twig->render((string) $email->getHtmlTemplate(), $context);
        $this->assertStringContainsString('2 700,00 Kč vč. DPH', $body);
        $this->assertStringNotContainsString('3 100,00', $body);
    }

    public function testRequestedEmailDropsThePaymentInstructionWhenCreditCoversTheWholeCycle(): void
    {
        [$contract, $request] = $this->createManualCycle(credit: self::MONTHLY_AMOUNT);

        ($this->requestedHandler)(new ManualBillingPaymentRequested(
            $contract->id,
            $request->id,
            ManualBillingReminderSchedule::STAGE_INITIAL,
            $this->clock->now(),
        ));

        $email = $this->lastTemplatedEmail();
        $context = $email->getContext();
        $this->assertSame('0,00', $context['amountInCzk']);
        // A 0 Kč QR is a valid but nonsensical instruction — no QR, no bank block.
        $this->assertNull($context['qrCodeDataUri']);
        $this->assertNull($context['bankAccount']);

        $body = $this->twig->render((string) $email->getHtmlTemplate(), $context);
        $this->assertStringNotContainsString('Platební údaje pro bankovní převod', $body);
        $this->assertStringNotContainsString('QR kód pro platbu', $body);
        // The e-mail still goes out as the cycle notification it is.
        $this->assertStringContainsString('0,00 Kč vč. DPH', $body);
    }

    public function testOverdueEmailWithoutCreditAsksForTheFullCycle(): void
    {
        [$contract, $request] = $this->createManualCycle(credit: 0);

        ($this->overdueHandler)(new ManualBillingPaymentOverdue(
            $contract->id,
            $request->id,
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST,
            $this->clock->now(),
        ));

        $context = $this->lastTemplatedEmail()->getContext();
        $this->assertSame('3 100,00', $context['amountInCzk']);
        $this->assertQrCarries($context, 310_000);
    }

    public function testOverdueEmailSubtractsCreditFromBothTheAmountAndTheQr(): void
    {
        [$contract, $request] = $this->createManualCycle(credit: self::CREDIT);

        ($this->overdueHandler)(new ManualBillingPaymentOverdue(
            $contract->id,
            $request->id,
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST,
            $this->clock->now(),
        ));

        $email = $this->lastTemplatedEmail();
        $context = $email->getContext();
        $this->assertSame(self::MONTHLY_AMOUNT, $request->amount);
        $this->assertSame('2 700,00', $context['amountInCzk']);
        $this->assertQrCarries($context, 270_000);

        $body = $this->twig->render((string) $email->getHtmlTemplate(), $context);
        $this->assertStringContainsString('2 700,00 Kč vč. DPH', $body);
    }

    public function testOverdueEmailDropsThePaymentInstructionWhenCreditCoversTheWholeCycle(): void
    {
        [$contract, $request] = $this->createManualCycle(credit: self::MONTHLY_AMOUNT);

        ($this->overdueHandler)(new ManualBillingPaymentOverdue(
            $contract->id,
            $request->id,
            ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL,
            $this->clock->now(),
        ));

        $context = $this->lastTemplatedEmail()->getContext();
        $this->assertSame('0,00', $context['amountInCzk']);
        $this->assertNull($context['qrCodeDataUri']);
        $this->assertNull($context['bankAccount']);
    }

    /**
     * The QR is served through the signed /qr-platba route, so the requested
     * amount is visible in the URL — assert it matches the displayed figure.
     *
     * @param array<string, mixed> $context
     */
    private function assertQrCarries(array $context, int $expectedAmountInHaler): void
    {
        $qrUrl = $context['qrCodeDataUri'];
        $this->assertIsString($qrUrl);
        $this->assertStringContainsString('/qr-platba/9100000123/'.$expectedAmountInHaler.'?', $qrUrl);
    }

    /**
     * A MANUAL_RECURRING contract mid-term (no proration) with a pending
     * payment request frozen at the full cycle amount.
     *
     * @return array{Contract, ManualPaymentRequest}
     */
    private function createManualCycle(int $credit): array
    {
        $source = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($source instanceof Order, 'No fixture order available to borrow a storage + user from.');

        $now = $this->clock->now();
        $startDate = new \DateTimeImmutable('2025-01-01');
        $endDate = new \DateTimeImmutable('2026-06-30');
        $periodStart = new \DateTimeImmutable('2025-07-01');

        $order = new Order(
            id: Uuid::v7(),
            user: $source->user,
            storage: $source->storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: self::MONTHLY_AMOUNT,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->assignVariableSymbol('9100000123');
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $now,
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyPaymentFrequency(PaymentFrequency::MONTHLY);
        // Pin the price so the cycle amount does not depend on the storage rate.
        $contract->applyIndividualMonthlyAmount(self::MONTHLY_AMOUNT, null, null, $now);
        $contract->scheduleNextBilling($periodStart, null);
        if ($credit > 0) {
            $contract->addCredit($credit);
        }
        $contract->popEvents();
        $this->entityManager->persist($contract);

        $request = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: $periodStart,
            periodEnd: new \DateTimeImmutable('2025-07-31'),
            amount: self::MONTHLY_AMOUNT,
            createdAt: $now,
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return [$contract, $request];
    }

    private function lastTemplatedEmail(): TemplatedEmail
    {
        $this->assertNotEmpty($this->sentEmails, 'No email was sent.');
        $email = $this->sentEmails[count($this->sentEmails) - 1];
        \assert($email instanceof TemplatedEmail);

        return $email;
    }
}
