<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

#[AsMessageHandler]
final readonly class ResendVerificationEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(ResendVerificationEmailCommand $command): void
    {
        $user = $this->userRepository->findByEmail($command->email);

        // Silently ignore if user doesn't exist or is already verified
        // This prevents email enumeration attacks
        if ($user === null || $user->isVerified()) {
            return;
        }

        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            routeName: 'app_verify_email',
            userId: (string) $user->id,
            userEmail: $user->email,
            extraParams: ['id' => (string) $user->id],
        );

        $email = (new TemplatedEmail())
            ->from(new Address('noreply@fajnesklady.cz', 'Fajné Sklady'))
            ->to(new Address($user->email, $user->fullName))
            ->subject('Please verify your email address')
            ->htmlTemplate('email/verification.html.twig')
            ->context([
                'name' => $user->fullName,
                'verificationUrl' => $signatureComponents->getSignedUrl(),
                'expiresAt' => $signatureComponents->getExpiresAt(),
            ]);

        $this->mailer->send($email);
    }
}
