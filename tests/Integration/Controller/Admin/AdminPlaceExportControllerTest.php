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
        self::assertTrue($this->rowsContainCellValue($rows, 'Sklad Praha - Centrum'));
        self::assertTrue($this->rowsContainCellValue($rows, 'Sklad Brno'));
    }
}
