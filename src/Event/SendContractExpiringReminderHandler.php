<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendContractExpiringReminderHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ContractExpiringSoon $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);
        $user = $contract->user;
        $storage = $contract->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        $portalUrl = $this->urlGenerator->generate(
            'portal_dashboard',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $subject = 1 === $event->daysRemaining
            ? 'Zítra končí Vaše smlouva - '.$place->name
            : sprintf('Vaše smlouva končí za %d dní - %s', $event->daysRemaining, $place->name);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject($subject)
            ->htmlTemplate('email/contract_expiring.html.twig')
            ->context([
                'name' => $user->fullName,
                'contractNumber' => $this->formatContractNumber($contract),
                'placeName' => $place->name,
                'placeAddress' => sprintf('%s, %s %s', $place->address, $place->postalCode, $place->city),
                'storageType' => $storageType->name,
                'storageNumber' => $storage->number,
                'endDate' => $contract->endDate?->format('d.m.Y'),
                'daysRemaining' => $event->daysRemaining,
                'portalUrl' => $portalUrl,
            ]);

        $this->mailer->send($email);
    }

    private function formatContractNumber(\App\Entity\Contract $contract): string
    {
        $date = $contract->createdAt;
        $uuidShort = substr($contract->id->toRfc4122(), 0, 8);

        return sprintf(
            '%s-%s-%s',
            $date->format('Y'),
            $date->format('md'),
            strtoupper($uuidShort),
        );
    }
}
