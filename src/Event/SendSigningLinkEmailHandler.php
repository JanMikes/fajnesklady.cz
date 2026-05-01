<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendSigningLinkEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(AdminOnboardingInitiated $event): void
    {
        if (null === $event->signingToken) {
            return;
        }

        $user = $this->userRepository->get($event->userId);

        $signingUrl = $this->urlGenerator->generate(
            'public_customer_signing',
            ['token' => $event->signingToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to(new Address($event->customerEmail, $user->fullName))
            ->subject('Podepište smlouvu - Fajnesklady.cz')
            ->htmlTemplate('email/signing_link.html.twig')
            ->context([
                'name' => $user->fullName,
                'signingUrl' => $signingUrl,
            ]);

        $this->mailer->send($email);
    }
}
