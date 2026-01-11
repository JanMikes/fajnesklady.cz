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
final readonly class SendWelcomeEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(EmailVerified $event): void
    {
        $user = $this->userRepository->findById($event->userId);
        if (null === $user) {
            return;
        }

        // Generate the login URL
        $loginUrl = $this->urlGenerator->generate(
            'app_login',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Create and send the welcome email
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('Welcome to Fajné Sklady!')
            ->htmlTemplate('email/welcome.html.twig')
            ->context([
                'name' => $user->getName(),
                'loginUrl' => $loginUrl,
            ]);

        $this->mailer->send($email);
    }
}
