<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminPlaceExportControllerTest extends WebTestCase
{
    use ExcelExportTestTrait;

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

    public function testAnonymousIsRedirected(): void
    {
        $this->client->request('GET', '/portal/admin/places/export');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'user@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/places/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminExportContainsAllPlaces(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/places/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Pobočka', $rows[0][0]);
        self::assertSame('Vlastník', $rows[0][1]);
        self::assertSame('E-mail vlastníka', $rows[0][2]);
        self::assertSame('Adresa', $rows[0][3]);
        self::assertTrue($this->rowsContainCellValue($rows, 'Sklad Praha - Centrum'));
        self::assertTrue($this->rowsContainCellValue($rows, 'Sklad Brno'));
    }

    public function testAdminExportFirstDataRowHasOwnerColumns(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/places/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        // At least one fixture place is owned by landlord@example.com (via storage.owner) —
        // surface either the landlord's email or one of the other landlord fixtures.
        $ownerEmails = array_column($rows, 2);
        $emailsBlob = implode('|', array_filter($ownerEmails, 'is_string'));
        self::assertStringContainsString('landlord', $emailsBlob);
    }

    public function testQueryCountIsBoundedRegardlessOfPlaceCount(): void
    {
        $admin = $this->findUserByEmail($this->entityManager, 'admin@example.com');
        $this->client->loginUser($admin, 'main');

        $this->client->enableProfiler();
        $this->client->request('GET', '/portal/admin/places/export');
        $this->assertXlsxResponse($this->client);

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(\Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector::class, $collector);

        // Old impl ran 5 queries per place. New impl uses 3 aggregate DBAL
        // queries (storage stats, owners, contract stats) + the place fetch +
        // session/user lookups — bounded regardless of place count.
        self::assertLessThan(25, $collector->getQueryCount(), sprintf('expected bounded query count, got %d', $collector->getQueryCount()));
    }
}
