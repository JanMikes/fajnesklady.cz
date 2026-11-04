<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Repository\HandoverProtocolRepository;
use App\Service\Handover\HandoverUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SendHandoverCompletedEmailHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private HandoverProtocolRepository $handoverProtocolRepository,
        private HandoverUrlGenerator $handoverUrlGenerator,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandoverCompleted $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $protocol = $this->handoverProtocolRepository->get($event->handoverProtocolId);
        $user = $contract->user;
        $storage = $contract->storage;
        $place = $storage->getPlace();
        $landlord = $storage->owner;

        // Tenant side resolved by landlord/admin — the tenant never filled
        // anything, so their mail confirms the takeover instead of "both sides filled".
        $tenantSkipped = null !== $protocol->tenantSkippedAt;

        $context = [
            'placeName' => $place->name,
            'storageNumber' => $storage->number,
            'tenantSkipped' => $tenantSkipped,
        ];

        // Email to tenant
        $this->sendEmail(
            $user->email,
            $user->fullName,
            ($tenantSkipped ? 'Sklad převzat, předávací protokol vyplněn - ' : 'Předávací protokol dokončen - ').$place->name,
            array_merge($context, [
                'name' => $user->fullName,
                'recipient' => 'tenant',
                'protocolUrl' => $this->handoverUrlGenerator->generateTenantView($protocol),
            ]),
            $event,
            $contract->order->id,
        );

        // Email to landlord
        if (null !== $landlord) {
            $this->sendEmail(
                $landlord->email,
                $landlord->fullName,
                'Předávací protokol dokončen - '.$place->name,
                array_merge($context, ['name' => $landlord->fullName, 'recipient' => 'landlord']),
                $event,
                $contract->order->id,
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendEmail(string $toEmail, string $toName, string $subject, array $context, HandoverCompleted $event, Uuid $orderId): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($toEmail, $toName))
            ->subject($subject)
            ->htmlTemplate('email/handover_completed.html.twig')
            ->context($context);
        $email->getHeaders()->addTextHeader('X-Order-Id', $orderId->toRfc4122());

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
