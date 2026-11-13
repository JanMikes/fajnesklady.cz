<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\Billing\CardPaymentStopSummaryFactory;
use App\Service\OrderStatusUrlGenerator;
use App\Service\RecurringPaymentCancelUrlGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendRecurringPaymentFailedEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private OrderStatusUrlGenerator $statusUrlGenerator,
        private LoggerInterface $logger,
        private CardPaymentStopSummaryFactory $summaryFactory,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecurringPaymentFailed $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $subject = match ($event->attempt) {
            1 => 'Platba za pronájem se nepodařila - Fajnesklady.cz',
            2 => 'Druhá neúspěšná platba za pronájem - Fajnesklady.cz',
            default => 'Poslední pokus o platbu selhal, hrozí ukončení smlouvy - Fajnesklady.cz',
        };
        $isFirstAttempt = 1 === $event->attempt;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate('email/recurring_payment_failed.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'attempt' => $event->attempt,
                'isFirstAttempt' => $isFirstAttempt,
                'reason' => $event->reason,
                // The signed cancel page leads with "change card", and its
                // fallback moves the contract to bank transfer — the tenant's
                // two ways to pay. A bare "cancel" button here made a tenant
                // cancel and then never pay (2026-11-12).
                'paymentOptionsUrl' => $contract->hasActiveRecurringPayment() ? $this->cancelUrlGenerator->generate($contract) : null,
                'summary' => $this->summaryFactory->create($contract, $this->clock->now()),
                'statusUrl' => $this->statusUrlGenerator->generate($contract->order),
            ]);

        $email->getHeaders()->addTextHeader('X-Order-Id', $contract->order->id->toRfc4122());

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send recurring payment failed email to tenant', [
                'contract_id' => $event->contractId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
