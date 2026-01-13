<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Tests for profile-related controllers.
 */
class ProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    // ===========================================
    // PROFILE VIEW
    // ===========================================

    public function testProfileViewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/profile');

        $this->assertResponseRedirects('/login');
    }

    public function testProfileViewIsAccessible(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $this->client->request('GET', '/portal/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Nastavení účtu');
    }

    public function testProfileViewDisplaysUserInfo(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $crawler = $this->client->request('GET', '/portal/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $user->email);
        $this->assertSelectorTextContains('body', $user->fullName);
    }

    // ===========================================
    // PROFILE EDIT
    // ===========================================

    public function testProfileEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/profile/edit');

        $this->assertResponseRedirects('/login');
    }

    public function testProfileEditIsAccessible(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $this->client->request('GET', '/portal/profile/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Upravit profil');
    }

    public function testProfileEditSubmissionUpdatesProfile(): void
    {
        $user = $this->createUser('profile-edit-test@example.com', UserRole::USER);
        $this->login($user);

        $crawler = $this->client->request('GET', '/portal/profile/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit změny')->form([
            'profile_form[firstName]' => 'Updated',
            'profile_form[lastName]' => 'Name',
            'profile_form[phone]' => '+420123456789',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/portal/profile');

        // Verify update
        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $user->id);
        $this->assertSame('Updated', $updatedUser->firstName);
        $this->assertSame('Name', $updatedUser->lastName);
        $this->assertSame('+420123456789', $updatedUser->phone);
    }

    // ===========================================
    // CHANGE PASSWORD
    // ===========================================

    public function testChangePasswordRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/profile/change-password');

        $this->assertResponseRedirects('/login');
    }

    public function testChangePasswordIsAccessible(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $this->client->request('GET', '/portal/profile/change-password');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Změnit heslo');
    }

    public function testChangePasswordWithInvalidCurrentPassword(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $crawler = $this->client->request('GET', '/portal/profile/change-password');
        $this->assertResponseIsSuccessful();

        // Use a strong password to pass validation (STRENGTH_MEDIUM requires mixed case, numbers, special chars)
        $form = $crawler->selectButton('Změnit heslo')->form([
            'change_password_form[currentPassword]' => 'wrong_password',
            'change_password_form[newPassword][first]' => 'NewStr0ng!Pass#2024',
            'change_password_form[newPassword][second]' => 'NewStr0ng!Pass#2024',
        ]);

        $this->client->submit($form);

        // Should show error flash for invalid current password
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-error');
    }

    // ===========================================
    // BILLING INFO
    // ===========================================

    public function testBillingInfoRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/profile/billing');

        $this->assertResponseRedirects('/login');
    }

    public function testBillingInfoIsAccessible(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->login($user);

        $this->client->request('GET', '/portal/profile/billing');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Fakturační údaje');
    }

    public function testBillingInfoFormSubmission(): void
    {
        $user = $this->createUser('billing-test@example.com', UserRole::USER);
        $this->login($user);

        $crawler = $this->client->request('GET', '/portal/profile/billing');
        $this->assertResponseIsSuccessful();

        // Find the form and submit it with billing data
        $form = $crawler->selectButton('Uložit fakturační údaje')->form([
            'billing_info_form[companyId]' => '12345678',
            'billing_info_form[companyName]' => 'Test Company s.r.o.',
            'billing_info_form[companyVatId]' => 'CZ12345678',
            'billing_info_form[billingStreet]' => 'Test Street 123',
            'billing_info_form[billingCity]' => 'Prague',
            'billing_info_form[billingPostalCode]' => '110 00',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/portal/profile');

        // Verify update
        $this->entityManager->clear();
        $updatedUser = $this->entityManager->find(User::class, $user->id);
        $this->assertSame('12345678', $updatedUser->companyId);
        $this->assertSame('Test Company s.r.o.', $updatedUser->companyName);
        $this->assertSame('CZ12345678', $updatedUser->companyVatId);
        $this->assertSame('Test Street 123', $updatedUser->billingStreet);
        $this->assertSame('Prague', $updatedUser->billingCity);
        $this->assertSame('110 00', $updatedUser->billingPostalCode);
    }

    public function testBillingInfoDisplayedOnProfileAfterUpdate(): void
    {
        $user = $this->createUser('billing-display@example.com', UserRole::USER);
        $this->login($user);

        // First, set billing info
        $crawler = $this->client->request('GET', '/portal/profile/billing');
        $form = $crawler->selectButton('Uložit fakturační údaje')->form([
            'billing_info_form[companyId]' => '87654321',
            'billing_info_form[companyName]' => 'Display Test s.r.o.',
            'billing_info_form[companyVatId]' => '',
            'billing_info_form[billingStreet]' => 'Display Street 456',
            'billing_info_form[billingCity]' => 'Brno',
            'billing_info_form[billingPostalCode]' => '602 00',
        ]);
        $this->client->submit($form);

        // Then verify it's displayed on profile
        $this->client->request('GET', '/portal/profile');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Display Test s.r.o.');
        $this->assertSelectorTextContains('body', '87654321');
    }

    // ===========================================
    // HELPER METHODS
    // ===========================================

    private function login(User $user): void
    {
        $this->client->loginUser($user, 'main');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
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
