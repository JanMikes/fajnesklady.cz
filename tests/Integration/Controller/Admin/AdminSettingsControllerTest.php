<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\PlatformSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminSettingsControllerTest extends WebTestCase
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

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/portal/admin/nastaveni');

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('POST', '/portal/admin/nastaveni', [
            'platform_settings_form' => ['bankTransferSurchargeInCzk' => '0'],
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSettingsPageLoads(): void
    {
        $this->loginAsAdmin();

        $this->client->request('GET', '/portal/admin/nastaveni');

        $this->assertResponseIsSuccessful();
    }

    public function testSavingZeroPersists(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/portal/admin/nastaveni', [
            'platform_settings_form' => ['bankTransferSurchargeInCzk' => '0'],
        ]);

        $this->assertResponseRedirects('/portal/admin/nastaveni');
        $this->assertSame(0, $this->reloadSettings()->bankTransferSurchargeInHaler);
    }

    public function testSavingNonZeroValuePersists(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/portal/admin/nastaveni', [
            'platform_settings_form' => ['bankTransferSurchargeInCzk' => '150'],
        ]);

        $this->assertResponseRedirects('/portal/admin/nastaveni');
        $this->assertSame(15_000, $this->reloadSettings()->bankTransferSurchargeInHaler);
    }

    public function testSavingWritesAuditLog(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/portal/admin/nastaveni', [
            'platform_settings_form' => ['bankTransferSurchargeInCzk' => '0'],
        ]);

        $this->assertResponseRedirects('/portal/admin/nastaveni');

        $this->entityManager->clear();
        $auditLog = $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->setParameter('entityType', 'platform_settings')
            ->setParameter('eventType', 'surcharge_changed')
            ->orderBy('al.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame(0, $auditLog->payload['new_value_haler']);
    }

    public function testNegativeValueIsRejected(): void
    {
        $this->loginAsAdmin();

        $this->client->request('POST', '/portal/admin/nastaveni', [
            'platform_settings_form' => ['bankTransferSurchargeInCzk' => '-5'],
        ]);

        // Invalid form → no dispatch; the row bootstrapped by getSettings() keeps the default 100 Kč.
        $this->assertSame(10_000, $this->reloadSettings()->bankTransferSurchargeInHaler);
    }

    private function reloadSettings(): PlatformSettings
    {
        $this->entityManager->clear();
        $settings = $this->entityManager->createQueryBuilder()
            ->select('ps')
            ->from(PlatformSettings::class, 'ps')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($settings instanceof PlatformSettings);

        return $settings;
    }

    private function loginAsAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }
}
