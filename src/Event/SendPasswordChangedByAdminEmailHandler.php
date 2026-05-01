<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class SendPasswordChangedByAdminEmailHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(PasswordChangedByAdmin $event): void
    {
        $loginUrl = $this->urlGenerator->generate(
            'app_login',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $resetPasswordUrl = $this->urlGenerator->generate(
            'app_request_password_reset',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajnesklady.cz'))
            ->to($event->email)
            ->subject('Vaše heslo bylo změněno administrátorem')
            ->htmlTemplate('email/password_changed_by_admin.html.twig')
            ->context([
                'userEmail' => $event->email,
                'loginUrl' => $loginUrl,
                'resetPasswordUrl' => $resetPasswordUrl,
            ]);

        $this->mailer->send($email);
    }
}
