<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminOrderExportControllerTest extends WebTestCase
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

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/portal/admin/orders/export');

        $this->assertResponseRedirects('/login');
    }

    public function testLandlordGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/orders/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanExport(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/orders/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        // Header row.
        self::assertSame('Číslo objednávky', $rows[0][0]);
        self::assertSame('Pobočka', $rows[0][8]);

        // Tenant fixture is the user behind several orders.
        self::assertTrue(
            $this->rowsContainCellValue($rows, 'tenant@example.com'),
            'Expected tenant@example.com in admin order export.',
        );

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('objednavky-', $disposition);
    }

    public function testCompanyCustomerIsExportedUnderTheCompanyName(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/orders/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Zákazník', $rows[0][3]);
        self::assertSame('Kontaktní osoba', $rows[0][4]);
        // Tenant fixture ordered as "Skladová Eva s.r.o." — the company heads
        // the Zákazník column, the signing person stays in its own column.
        self::assertTrue($this->rowsContainCellValue($rows, 'Skladová Eva s.r.o.'));
        self::assertTrue($this->rowsContainCellValue($rows, 'Eva Najemce'));
    }

    public function testFilterIsHonoured(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/orders/export?filter=free');

        $body = $this->assertXlsxResponse($this->client);
        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('-free-', $disposition);

        // Read produces at least the header — filter may match zero or more rows.
        $rows = $this->readXlsxRows($body);
        self::assertNotEmpty($rows);
        self::assertSame('Číslo objednávky', $rows[0][0]);
    }
}
