<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserExportControllerTest extends WebTestCase
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
        $this->client->request('GET', '/portal/users/export');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'user@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'landlord@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminExportLists(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Jméno', $rows[0][0]);
        self::assertTrue($this->rowsContainCellValue($rows, 'admin@example.com'));
        self::assertTrue($this->rowsContainCellValue($rows, 'tenant@example.com'));
    }

    public function testActiveFilter(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export?filter=active');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        // tenant@ has an active fixture contract; admin@ does not.
        self::assertTrue($this->rowsContainCellValue($rows, 'tenant@example.com'));
        self::assertFalse($this->rowsContainCellValue($rows, 'admin@example.com'));
    }

    public function testHeaderIncludesOnboardedDebtorAndLastLoginColumns(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        $header = $rows[0];
        self::assertContains('Onboardovaný', $header);
        self::assertContains('Dlužník', $header);
        self::assertContains('Poslední přihlášení', $header);
    }

    public function testRoleColumnUsesNajemceLabel(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        // ROLE_USER label is now "Nájemce" (not "Uživatel"). At least one row
        // (e.g. tenant@example.com) must surface that label in the Role column.
        self::assertTrue($this->rowsContainCellValue($rows, 'Nájemce'));
        self::assertFalse($this->rowsContainCellValue($rows, 'Uživatel'));
    }

    public function testLastLoginAtIsExported(): void
    {
        // Stamp lastLoginAt on the tenant fixture so the export has at least one
        // populated cell to assert against.
        $tenant = $this->findUserByEmail($this->entityManager, 'tenant@example.com');
        $tenant->recordLogin(new \DateTimeImmutable('2025-06-15 09:30:00'));
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'admin@example.com'), 'main');
        $this->client->request('GET', '/portal/users/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        // The exporter formats DATETIME cells as "dd.mm.yyyy hh:mm" — match a
        // substring that is robust against timezone display quirks while still
        // confirming the cell was emitted.
        $foundFormatted = false;
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell instanceof \DateTimeInterface
                    && '2025-06-15' === $cell->format('Y-m-d')
                ) {
                    $foundFormatted = true;
                    break 2;
                }
                if (is_string($cell) && str_contains($cell, '15.06.2025')) {
                    $foundFormatted = true;
                    break 2;
                }
            }
        }
        self::assertTrue($foundFormatted, 'Expected lastLoginAt cell with 2025-06-15 in the export.');
    }
}
