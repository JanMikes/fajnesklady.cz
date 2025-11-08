<?php

declare(strict_types=1);

namespace App\User\Event;

use App\User\Repository\UserRepositoryInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

#[AsMessageHandler]
final readonly class SendVerificationEmailHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(UserRegistered $event): void
    {
        $user = $this->userRepository->findById($event->userId);
        if (null === $user) {
            return;
        }

        // Generate verification token/URL
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            routeName: 'app_verify_email',
            userId: (string) $user->getId(),
            userEmail: $user->getEmail(),
            extraParams: [],
        );

        // Create email with verification link
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('Please verify your email address')
            ->htmlTemplate('email/verification.html.twig')
            ->context([
                'name' => $user->getName(),
                'verificationUrl' => $signatureComponents->getSignedUrl(),
                'expiresAt' => $signatureComponents->getExpiresAt(),
            ]);

        // Send email
        $this->mailer->send($email);
    }
}
