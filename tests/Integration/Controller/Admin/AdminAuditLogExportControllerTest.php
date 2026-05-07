<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminAuditLogExportControllerTest extends WebTestCase
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
        $this->client->request('GET', '/portal/admin/audit-log/export');

        $this->assertResponseRedirects('/login');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'user@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/audit-log/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminExportProducesXlsxWithHeader(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/audit-log/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Čas', $rows[0][0]);
        self::assertSame('Událost', $rows[0][4]);
    }
}
