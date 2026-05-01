<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class UserChangePasswordControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testRequiresAuthentication(): void
    {
        $target = $this->createUser('change-pwd-target@example.com', UserRole::USER);

        $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        $user = $this->createUser('change-pwd-user@example.com', UserRole::USER);
        $target = $this->createUser('change-pwd-user-target@example.com', UserRole::USER);

        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeniedForLandlord(): void
    {
        $landlord = $this->createUser('change-pwd-landlord@example.com', UserRole::LANDLORD);
        $target = $this->createUser('change-pwd-landlord-target@example.com', UserRole::USER);

        $this->client->loginUser($landlord, 'main');
        $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotChangeOwnPassword(): void
    {
        $admin = $this->createUser('change-pwd-self-admin@example.com', UserRole::ADMIN);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/portal/users/'.$admin->id->toRfc4122().'/change-password');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testFormIsAccessibleByAdmin(): void
    {
        $admin = $this->createUser('change-pwd-admin@example.com', UserRole::ADMIN);
        $target = $this->createUser('change-pwd-admin-target@example.com', UserRole::USER);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Změnit heslo uživatele');
    }

    public function testAdminCanChangeAnotherUserPassword(): void
    {
        $admin = $this->createUser('change-pwd-success-admin@example.com', UserRole::ADMIN);
        $target = $this->createUser('change-pwd-success-target@example.com', UserRole::USER);

        $this->client->loginUser($admin, 'main');

        $crawler = $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit nové heslo')->form([
            'admin_user_password_form[newPassword][first]' => 'BrandNewPassword123',
            'admin_user_password_form[newPassword][second]' => 'BrandNewPassword123',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/portal/users/'.$target->id->toRfc4122());

        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $target->id);
        \assert($updatedUser instanceof User);
        $this->assertTrue($this->passwordHasher->isPasswordValid($updatedUser, 'BrandNewPassword123'));
        $this->assertFalse($this->passwordHasher->isPasswordValid($updatedUser, 'password'));
    }

    public function testMismatchedRepeatPasswordShowsFormError(): void
    {
        $admin = $this->createUser('change-pwd-mismatch-admin@example.com', UserRole::ADMIN);
        $target = $this->createUser('change-pwd-mismatch-target@example.com', UserRole::USER);

        $this->client->loginUser($admin, 'main');

        $crawler = $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit nové heslo')->form([
            'admin_user_password_form[newPassword][first]' => 'FirstPassword123',
            'admin_user_password_form[newPassword][second]' => 'OtherPassword456',
        ]);

        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Hesla se neshodují.');

        $this->entityManager->clear();
        $unchangedUser = $this->entityManager->find(User::class, $target->id);
        \assert($unchangedUser instanceof User);
        $this->assertFalse($this->passwordHasher->isPasswordValid($unchangedUser, 'FirstPassword123'));
    }

    public function testTooShortPasswordShowsFormError(): void
    {
        $admin = $this->createUser('change-pwd-short-admin@example.com', UserRole::ADMIN);
        $target = $this->createUser('change-pwd-short-target@example.com', UserRole::USER);

        $this->client->loginUser($admin, 'main');

        $crawler = $this->client->request('GET', '/portal/users/'.$target->id->toRfc4122().'/change-password');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit nové heslo')->form([
            'admin_user_password_form[newPassword][first]' => 'short',
            'admin_user_password_form[newPassword][second]' => 'short',
        ]);

        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'Heslo musí mít alespoň 8 znaků.');

        $this->entityManager->clear();
        $unchangedUser = $this->entityManager->find(User::class, $target->id);
        \assert($unchangedUser instanceof User);
        $this->assertFalse($this->passwordHasher->isPasswordValid($unchangedUser, 'short'));
    }

    private function createUser(string $email, UserRole $role): User
    {
        $now = new \DateTimeImmutable();
        $user = new User(
            id: Uuid::v7(),
            email: $email,
            password: password_hash('password', PASSWORD_BCRYPT),
            firstName: 'Test',
            lastName: 'User',
            createdAt: $now,
        );
        $user->markAsVerified($now);

        if (UserRole::USER !== $role) {
            $user->changeRole($role, $now);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
