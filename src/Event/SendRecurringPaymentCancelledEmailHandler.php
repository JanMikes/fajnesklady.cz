<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendRecurringPaymentCancelledEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(RecurringPaymentCancelled $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajne Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Pravidelna platba zrusena - Fajne Sklady')
            ->htmlTemplate('email/recurring_payment_cancelled.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
            ]);

        $this->mailer->send($email);
    }
}
