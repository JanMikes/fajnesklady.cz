<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\Billing\CzechDayCount;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\OrderStatusUrlGenerator;
use App\Service\Payment\QrPaymentGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Sends the d-7 / d-2 / d-0 customer reminder e-mail for a MANUAL_RECURRING
 * contract. Three Twig templates share the same context shape; the stage
 * decides which subject + template is used.
 */
#[AsMessageHandler]
final readonly class SendManualBillingPaymentRequestedEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private QrPaymentGenerator $qrPaymentGenerator,
        private RecurringAmountCalculator $amountCalculator,
    ) {
    }

    public function __invoke(ManualBillingPaymentRequested $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $request = $this->manualPaymentRequestRepository->get($event->manualPaymentRequestId);

        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Stages fire on place-configurable offsets, and a late-onboarded
        // contract catches up off its nominal day — compute the actual
        // day distance to the due date instead of hardcoding -7/-2/0.
        $daysUntilDue = (int) $event->occurredOn->setTime(0, 0, 0)
            ->diff($request->periodStart->setTime(0, 0, 0))
            ->format('%r%a');
        $dueWhen = match (true) {
            $daysUntilDue > 0 => sprintf('za %s', CzechDayCount::days($daysUntilDue)),
            0 === $daysUntilDue => 'dnes',
            default => 'po splatnosti',
        };

        [$subject, $template] = match ($event->stage) {
            ManualBillingReminderSchedule::STAGE_INITIAL => [
                $daysUntilDue > 0
                    ? sprintf('Vaše platba bude splatná %s — Fajnesklady.cz', $dueWhen)
                    : 'Vaše platba je splatná — Fajnesklady.cz',
                'email/manual_billing_payment_initial.html.twig',
            ],
            ManualBillingReminderSchedule::STAGE_REMINDER => [
                $daysUntilDue > 0
                    ? sprintf('Připomenutí: platba splatná %s — Fajnesklady.cz', $dueWhen)
                    : 'Připomenutí: platba je splatná — Fajnesklady.cz',
                'email/manual_billing_payment_reminder.html.twig',
            ],
            ManualBillingReminderSchedule::STAGE_FINAL_DUE, 'manual' => [
                match (true) {
                    $daysUntilDue > 0 => sprintf('Platba je splatná %s — Fajnesklady.cz', $dueWhen),
                    0 === $daysUntilDue => 'Platba je splatná dnes — Fajnesklady.cz',
                    default => 'Platba je po splatnosti — Fajnesklady.cz',
                },
                'email/manual_billing_payment_due_today.html.twig',
            ],
            default => [null, null],
        };

        if (null === $subject || null === $template) {
            $this->logger->warning('Unknown manual-billing reminder stage', [
                'stage' => $event->stage,
                'contract_id' => $contract->id->toRfc4122(),
            ]);

            return;
        }

        $statusUrl = $this->statusUrlGenerator->generate($contract->order);
        $order = $contract->order;

        // Spec 091 D3: credit already sitting on the contract reduces what we ask
        // for — the frozen ManualPaymentRequest::$amount stays the full cycle.
        // Computed ONCE so the displayed amount and the QR can never disagree.
        $amountToRequest = $this->amountCalculator->amountToRequest($contract, $event->occurredOn);
        // Credit fully covers the cycle → there is nothing to transfer. A QR for
        // 0 Kč is a valid SPD string (`AM:0.00`) but a nonsensical instruction,
        // so drop the whole bank block (the template gates it on `bankAccount`)
        // and still send the e-mail as the cycle notification it is.
        $hasAmountToPay = $amountToRequest > 0;

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
                'amountInCzk' => number_format($amountToRequest / 100, 2, ',', ' '),
                'periodStart' => $request->periodStart,
                'periodEnd' => $request->periodEnd,
                'daysUntilDue' => $daysUntilDue,
                'dueWhen' => $dueWhen,
                'statusUrl' => $statusUrl,
                'bankAccount' => $hasAmountToPay ? $this->qrPaymentGenerator->getBankAccountFormatted() : null,
                'variableSymbol' => $order->variableSymbol,
                'qrCodeDataUri' => $hasAmountToPay && null !== $order->variableSymbol
                    ? $this->qrPaymentGenerator->generateImageUrl($order->variableSymbol, $amountToRequest)
                    : null,
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send manual-billing reminder email', [
                'contract_id' => $contract->id->toRfc4122(),
                'stage' => $event->stage,
                'exception' => $e,
            ]);
        }
    }
}
