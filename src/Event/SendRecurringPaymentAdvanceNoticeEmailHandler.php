<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\AdvanceNoticeReason;
use App\Repository\ContractRepository;
use App\Service\RecurringPaymentCancelUrlGenerator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Sends the 7-business-day advance notice required by Podmínky opakovaných
 * plateb čl. V — either before a charge that follows a ≥6-month gap
 * (automatic, fired by the daily cron), or before a parameter change
 * (admin-triggered).
 *
 * Marks the contract's lastAdvanceNoticeSentAt for idempotency: the daily cron
 * checks this before firing again, so the same event won't spam the customer.
 */
#[AsMessageHandler]
final readonly class SendRecurringPaymentAdvanceNoticeEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private RecurringPaymentCancelUrlGenerator $cancelUrlGenerator,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecurringPaymentAdvanceNoticeNeeded $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $currentAmountInCzk = $storage->getEffectivePricePerMonthInCzk();
        $newAmountInCzk = null !== $event->newAmount ? $event->newAmount / 100 : null;

        $subject = match ($event->reason) {
            AdvanceNoticeReason::SIX_MONTH_GAP => 'Připomenutí: blíží se opakovaná platba - Fajnesklady.cz',
            AdvanceNoticeReason::PARAMETER_CHANGE => 'Změna parametrů opakované platby - Fajnesklady.cz',
        };

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate('email/recurring_payment_advance_notice.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'reason' => $event->reason,
                'currentAmount' => number_format($currentAmountInCzk, 2, ',', ' '),
                'newAmount' => null !== $newAmountInCzk ? number_format($newAmountInCzk, 2, ',', ' ') : null,
                'nextBillingDate' => $contract->nextBillingDate?->format('d.m.Y'),
                'lastBilledAt' => $contract->lastBilledAt?->format('d.m.Y'),
                'adminNote' => $event->adminNote,
                'cancelUrl' => $contract->hasActiveRecurringPayment() ? $this->cancelUrlGenerator->generate($contract) : null,
            ]);

        try {
            $this->mailer->send($email);
            $contract->recordAdvanceNoticeSent($this->clock->now());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send recurring-payment advance-notice e-mail', [
                'contract_id' => $event->contractId->toRfc4122(),
                'reason' => $event->reason->value,
                'exception' => $e,
            ]);
        }
    }
}
