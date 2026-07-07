<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\Billing\CzechDayCount;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Sends the d+3 / d+7 overdue chase e-mail to the customer for a missed
 * MANUAL_RECURRING cycle. From d+8 onwards the admin overdue queue takes
 * over (no further automated customer-facing nags).
 */
#[AsMessageHandler]
final readonly class SendManualBillingPaymentOverdueEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private QrPaymentGenerator $qrPaymentGenerator,
    ) {
    }

    public function __invoke(ManualBillingPaymentOverdue $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $request = $this->manualPaymentRequestRepository->get($event->manualPaymentRequestId);

        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Overdue offsets are place-configurable and a late-onboarded
        // contract catches up off its nominal day — compute the actual
        // days-overdue count instead of hardcoding +3/+7.
        $daysOverdue = max(1, (int) $request->periodStart->setTime(0, 0, 0)
            ->diff($event->occurredOn->setTime(0, 0, 0))
            ->format('%r%a'));
        $overdueDays = CzechDayCount::days($daysOverdue);

        [$subject, $template] = match ($event->stage) {
            ManualBillingReminderSchedule::STAGE_OVERDUE_FIRST => [
                sprintf('Platba je %s po splatnosti — Fajnesklady.cz', $overdueDays),
                'email/manual_billing_payment_overdue_first.html.twig',
            ],
            ManualBillingReminderSchedule::STAGE_OVERDUE_FINAL => [
                sprintf('Poslední upomínka: %s po splatnosti — Fajnesklady.cz', $overdueDays),
                'email/manual_billing_payment_overdue_final.html.twig',
            ],
            default => [null, null],
        };

        if (null === $subject || null === $template) {
            $this->logger->warning('Unknown manual-billing overdue stage', [
                'stage' => $event->stage,
                'contract_id' => $contract->id->toRfc4122(),
            ]);

            return;
        }

        $statusUrl = $this->statusUrlGenerator->generate($contract->order);
        $order = $contract->order;

        // Spec 076: every manual cycle is paid by bank transfer — the dispatcher
        // guarantees a variable symbol before this event fires.
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'amountInCzk' => number_format($request->amount / 100, 2, ',', ' '),
                'periodStart' => $request->periodStart,
                'periodEnd' => $request->periodEnd,
                'overdueDays' => $overdueDays,
                'statusUrl' => $statusUrl,
                'bankAccount' => $this->qrPaymentGenerator->getBankAccountFormatted(),
                'variableSymbol' => $order->variableSymbol,
                'qrCodeDataUri' => null !== $order->variableSymbol
                    ? $this->qrPaymentGenerator->generateImageUrl($order->variableSymbol, $request->amount)
                    : null,
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send manual-billing overdue email', [
                'contract_id' => $contract->id->toRfc4122(),
                'stage' => $event->stage,
                'exception' => $e,
            ]);
        }
    }
}
