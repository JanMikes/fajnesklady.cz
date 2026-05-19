<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\HandoverProtocolRepository;
use App\Service\Handover\HandoverUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendHandoverReminderToTenantHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private MailerInterface $mailer,
        private HandoverUrlGenerator $handoverUrlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandoverReminderDue $event): void
    {
        $protocol = $this->handoverProtocolRepository->get($event->handoverProtocolId);

        if (!$protocol->needsTenantCompletion()) {
            return;
        }

        $contract = $protocol->contract;
        $user = $contract->user;
        $storage = $contract->storage;
        $place = $storage->getPlace();

        $handoverUrl = $this->handoverUrlGenerator->generateTenantView($protocol);

        $isUrgent = $event->reminderNumber >= 3;
        $subject = $isUrgent
            ? 'Urgentní: Předávací protokol stále nevyplněn - '.$place->name
            : 'Připomínka: Předávací protokol čeká na vyplnění - '.$place->name;

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate('email/handover_reminder_tenant.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageNumber' => $storage->number,
                'isUrgent' => $isUrgent,
                'handoverUrl' => $handoverUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send handover reminder to tenant', [
                'handover_protocol_id' => $event->handoverProtocolId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
