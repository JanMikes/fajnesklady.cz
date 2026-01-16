<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
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
    ) {
    }

    public function __invoke(RecurringPaymentFailed $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $isFirstAttempt = 1 === $event->attempt;
        $subject = $isFirstAttempt
            ? 'Platba za pronajem se nepodarila - Fajne Sklady'
            : 'Druha neuspesna platba - pronajem bude zrusen - Fajne Sklady';

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajne Sklady'))
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
            ]);

        $this->mailer->send($email);
    }
}
