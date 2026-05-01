<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\HandoverProtocolRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendHandoverRequestToTenantHandler
{
    public function __construct(
        private HandoverProtocolRepository $handoverProtocolRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(HandoverProtocolCreated $event): void
    {
        $protocol = $this->handoverProtocolRepository->get($event->handoverProtocolId);
        $contract = $protocol->contract;
        $user = $contract->user;
        $storage = $contract->storage;
        $place = $storage->getPlace();

        $handoverUrl = $this->urlGenerator->generate(
            'portal_user_handover_view',
            ['id' => $protocol->id],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Předávací protokol - prosím vyplňte - '.$place->name)
            ->htmlTemplate('email/handover_request_tenant.html.twig')
            ->context([
                'name' => $user->fullName,
                'placeName' => $place->name,
                'storageNumber' => $storage->number,
                'endDate' => $contract->getEffectiveEndDate()?->format('d.m.Y'),
                'handoverUrl' => $handoverUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send handover request email to tenant', [
                'handover_protocol_id' => $event->handoverProtocolId->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
