<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\RecurringPaymentCancelUrlGenerator;
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
        private LoggerInterface $logger,
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
            1 => 'Platba za pronájem se nepodařila - Fajné Sklady',
            2 => 'Druhá neúspěšná platba za pronájem - Fajné Sklady',
            default => 'Opakovaná neúspěšná platba - pronájem bude zrušen - Fajné Sklady',
        };
        $isFirstAttempt = 1 === $event->attempt;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
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
                'cancelUrl' => $contract->hasActiveRecurringPayment() ? $this->cancelUrlGenerator->generate($contract) : null,
            ]);

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
