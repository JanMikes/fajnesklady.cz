<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminOverdueExportControllerTest extends WebTestCase
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
        $this->client->request('GET', '/portal/admin/po-splatnosti/export');

        $this->assertResponseRedirects('/login');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/po-splatnosti/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminExportContainsHeader(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/po-splatnosti/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Zákazník', $rows[0][0]);
        self::assertSame('Dluh (Kč)', $rows[0][9]);
    }
}
