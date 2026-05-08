<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Security;

use App\DataFixtures\UserFixtures;
use App\Entity\User;
use App\Service\Security\RecordLoginSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class RecordLoginSubscriberTest extends KernelTestCase
{
    private RecordLoginSubscriber $subscriber;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->subscriber = $container->get(RecordLoginSubscriber::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testSubscribesToLoginSuccessEvent(): void
    {
        $events = RecordLoginSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(LoginSuccessEvent::class, $events);
        self::assertSame('onLoginSuccess', $events[LoginSuccessEvent::class]);
    }

    public function testRecordsLastLoginAtForUser(): void
    {
        // UNVERIFIED_EMAIL is the only fixture user without a seeded lastLoginAt.
        $user = $this->findUserByEmail(UserFixtures::UNVERIFIED_EMAIL);
        self::assertNull($user->lastLoginAt);

        $event = $this->buildLoginSuccessEvent($user);
        $this->subscriber->onLoginSuccess($event);

        $this->entityManager->clear();
        $reloaded = $this->findUserByEmail(UserFixtures::UNVERIFIED_EMAIL);

        self::assertNotNull($reloaded->lastLoginAt);
        self::assertEquals(
            $this->clock->now()->format('Y-m-d H:i:s'),
            $reloaded->lastLoginAt->format('Y-m-d H:i:s'),
        );
    }

    public function testIgnoresNonAppUsers(): void
    {
        $foreignUser = new \Symfony\Component\Security\Core\User\InMemoryUser('not-app@example.com', null);
        $passport = new SelfValidatingPassport(new UserBadge($foreignUser->getUserIdentifier(), static fn () => $foreignUser));
        $token = new UsernamePasswordToken($foreignUser, 'main');
        $event = new LoginSuccessEvent(
            authenticator: $this->createStub(AuthenticatorInterface::class),
            passport: $passport,
            authenticatedToken: $token,
            request: Request::create('/login'),
            response: null,
            firewallName: 'main',
        );

        $this->subscriber->onLoginSuccess($event);

        // No exception, no flush impact — nothing to assert beyond reaching this point.
        self::assertTrue(true);
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User "%s" not found', $email));

        return $user;
    }

    private function buildLoginSuccessEvent(User $user): LoginSuccessEvent
    {
        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return new LoginSuccessEvent(
            authenticator: $this->createStub(AuthenticatorInterface::class),
            passport: $passport,
            authenticatedToken: $token,
            request: Request::create('/login'),
            response: null,
            firewallName: 'main',
        );
    }
}
