<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Pravidelná platba zrušena - Fajné Sklady')
            ->htmlTemplate('email/recurring_payment_cancelled.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send recurring payment cancelled email to tenant', [
                'contract_id' => $event->contractId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
