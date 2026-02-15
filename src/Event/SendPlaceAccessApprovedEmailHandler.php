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
final readonly class SendPlaceAccessApprovedEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private PlaceRepository $placeRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(PlaceAccessRequestApproved $event): void
    {
        $landlord = $this->userRepository->get($event->landlordId);
        $place = $this->placeRepository->get($event->placeId);

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($landlord->email, $landlord->fullName))
            ->subject('Přístup k místu '.$place->name.' byl schválen')
            ->htmlTemplate('email/place_access_approved.html.twig')
            ->context([
                'name' => $landlord->fullName,
                'placeName' => $place->name,
            ]);

        $this->mailer->send($email);
    }
}
