<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

#[AsMessageHandler]
final readonly class SendVerificationEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(UserRegistered $event): void
    {
        $user = $this->userRepository->get($event->userId);

        // Passwordless users (e.g. created during guest checkout) cannot log in,
        // so sending a verification email would be confusing and pointless.
        if (!$user->hasPassword()) {
            return;
        }

        // Generate verification token/URL
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            routeName: 'app_verify_email',
            userId: (string) $user->id,
            userEmail: $user->email,
            extraParams: ['id' => (string) $user->id],
        );

        // Create email with verification link
        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Ověřte prosím svou e-mailovou adresu')
            ->htmlTemplate('email/verification.html.twig')
            ->context([
                'name' => $user->fullName,
                'verificationUrl' => $signatureComponents->getSignedUrl(),
                'expiresAt' => $signatureComponents->getExpiresAt(),
            ]);

        // Send email
        $this->mailer->send($email);
    }
}
