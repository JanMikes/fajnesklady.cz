<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendPasswordResetEmailHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(PasswordResetRequested $event): void
    {
        // Generate the reset URL
        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password',
            ['token' => $event->resetToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Create and send the email
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to($event->email)
            ->subject('Obnovení hesla')
            ->htmlTemplate('user/email/reset_password.html.twig')
            ->context([
                'resetUrl' => $resetUrl,
                'email' => $event->email,
            ]);

        $this->mailer->send($email);
    }
}
