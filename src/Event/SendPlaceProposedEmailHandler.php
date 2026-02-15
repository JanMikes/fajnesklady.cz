<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\PlaceRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendPlaceProposedEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PlaceRepository $placeRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(PlaceProposed $event): void
    {
        $user = $this->userRepository->get($event->proposedById);
        $place = $this->placeRepository->get($event->placeId);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address('admin@fajnesklady.cz', 'Admin'))
            ->subject('Nový návrh místa: '.$place->name)
            ->htmlTemplate('email/place_proposed.html.twig')
            ->context([
                'userName' => $user->fullName,
                'userEmail' => $user->email,
                'placeName' => $place->name,
                'placeCity' => $place->city,
            ]);

        $this->mailer->send($email);
    }
}
