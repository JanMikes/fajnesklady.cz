<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LandlordOrderExportControllerTest extends WebTestCase
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
        $this->client->request('GET', '/portal/landlord/orders/export');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'user@example.com'), 'main');
        $this->client->request('GET', '/portal/landlord/orders/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordExportContainsOnlyOwnOrders(): void
    {
        $landlord = $this->findUserByEmail($this->entityManager, 'landlord@example.com');
        $this->client->loginUser($landlord, 'main');
        $this->client->request('GET', '/portal/landlord/orders/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Číslo objednávky', $rows[0][0]);
        self::assertSame('Pobočka', $rows[0][7]);

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('objednavky-', $disposition);

        // Landlord owns Praha Centrum + Jih storages — Brno (admin@) and Ostrava (landlord2@) must be absent.
        self::assertFalse(
            $this->rowsContainCellValue($rows, 'Sklad Brno'),
            'Landlord export must not contain Brno orders.',
        );
        self::assertFalse(
            $this->rowsContainCellValue($rows, 'Sklad Ostrava'),
            'Landlord export must not contain Ostrava (landlord2) orders.',
        );
    }

    public function testLandlord2GetsOnlyOwnInvoices(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'landlord2@example.com'), 'main');
        $this->client->request('GET', '/portal/landlord/orders/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertFalse(
            $this->rowsContainCellValue($rows, 'Sklad Praha - Centrum'),
            'landlord2 export must not contain landlord1 orders.',
        );
    }
}
