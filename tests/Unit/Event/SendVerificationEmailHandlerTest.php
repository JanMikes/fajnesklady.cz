<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\User;
use App\Event\SendVerificationEmailHandler;
use App\Event\UserRegistered;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Uid\Uuid;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class SendVerificationEmailHandlerTest extends TestCase
{
    public function testSendsVerificationEmailForUserWithPassword(): void
    {
        $userId = Uuid::v7();
        $user = new User($userId, 'user@example.com', 'hashed_password', 'Jan', 'Novak', new \DateTimeImmutable());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('get')->with($userId)->willReturn($user);

        $verifyEmailHelper = $this->createMock(VerifyEmailHelperInterface::class);
        $verifyEmailHelper->expects($this->once())
            ->method('generateSignature')
            ->willReturn(new VerifyEmailSignatureComponents(
                new \DateTimeImmutable('+1 day'),
                'https://example.com/verify?token=abc',
                time(),
            ));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $handler = new SendVerificationEmailHandler($userRepository, $verifyEmailHelper, $mailer);
        $handler(new UserRegistered($userId, 'user@example.com', 'Jan', 'Novak', new \DateTimeImmutable()));
    }

    public function testDoesNotSendVerificationEmailForPasswordlessUser(): void
    {
        $userId = Uuid::v7();
        $user = new User($userId, 'guest@example.com', null, 'Guest', 'User', new \DateTimeImmutable());

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('get')->with($userId)->willReturn($user);

        $verifyEmailHelper = $this->createMock(VerifyEmailHelperInterface::class);
        $verifyEmailHelper->expects($this->never())->method('generateSignature');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $handler = new SendVerificationEmailHandler($userRepository, $verifyEmailHelper, $mailer);
        $handler(new UserRegistered($userId, 'guest@example.com', 'Guest', 'User', new \DateTimeImmutable()));
    }
}
