<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendPlaceAccessRequestEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PlaceRepository $placeRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(PlaceAccessRequested $event): void
    {
        $user = $this->userRepository->get($event->requestedById);
        $place = $this->placeRepository->get($event->placeId);

        $requestsUrl = $this->urlGenerator->generate(
            'portal_admin_place_access_requests',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address('admin@fajnesklady.cz', 'Admin'))
            ->subject('Nová žádost o přístup k místu: '.$place->name)
            ->htmlTemplate('email/place_access_requested.html.twig')
            ->context([
                'userName' => $user->fullName,
                'userEmail' => $user->email,
                'userPhone' => $user->phone,
                'companyName' => $user->companyName,
                'companyId' => $user->companyId,
                'placeName' => $place->name,
                'requestsUrl' => $requestsUrl,
            ]);

        $this->mailer->send($email);
    }
}
