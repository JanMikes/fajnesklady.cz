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
final readonly class SendHandoverCompletedEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandoverCompleted $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $place = $storage->getPlace();
        $landlord = $storage->owner;

        $context = [
            'placeName' => $place->name,
            'storageNumber' => $storage->number,
        ];

        // Email to tenant
        $this->sendEmail(
            $user->email,
            $user->fullName,
            'Předávací protokol dokončen - '.$place->name,
            array_merge($context, ['name' => $user->fullName]),
            $event,
        );

        // Email to landlord
        if (null !== $landlord) {
            $this->sendEmail(
                $landlord->email,
                $landlord->fullName,
                'Předávací protokol dokončen - '.$place->name,
                array_merge($context, ['name' => $landlord->fullName]),
                $event,
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendEmail(string $toEmail, string $toName, string $subject, array $context, HandoverCompleted $event): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($toEmail, $toName))
            ->subject($subject)
            ->htmlTemplate('email/handover_completed.html.twig')
            ->context($context);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send handover completed email', [
                'handover_protocol_id' => $event->handoverProtocolId->toRfc4122(),
                'recipient' => $toEmail,
                'exception' => $e,
            ]);
        }
    }
}
