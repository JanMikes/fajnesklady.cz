<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\Billing\ManualBillingReminderSchedule;
use App\Service\OrderStatusUrlGenerator;
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

        [$subject, $template] = match ($event->stage) {
            ManualBillingReminderSchedule::STAGE_INITIAL => [
                'Vaše platba bude splatná za 7 dní — Fajnesklady.cz',
                'email/manual_billing_payment_initial.html.twig',
            ],
            ManualBillingReminderSchedule::STAGE_REMINDER => [
                'Připomenutí: platba splatná za 2 dny — Fajnesklady.cz',
                'email/manual_billing_payment_reminder.html.twig',
            ],
            ManualBillingReminderSchedule::STAGE_FINAL_DUE, 'manual' => [
                'Platba je nyní splatná — Fajnesklady.cz',
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
                'gatewayUrl' => $request->goPayGatewayUrl,
                'statusUrl' => $statusUrl,
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
