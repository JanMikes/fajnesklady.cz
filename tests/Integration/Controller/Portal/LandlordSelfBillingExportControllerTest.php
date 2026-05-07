<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\SelfBillingInvoice;
use App\Entity\User;
use App\Tests\Integration\Controller\ExcelExportTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class LandlordSelfBillingExportControllerTest extends WebTestCase
{
    use ExcelExportTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->clock = static::getContainer()->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAnonymousIsRedirected(): void
    {
        $this->client->request('GET', '/portal/landlord/self-billing/export');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserGetsForbidden(): void
    {
        $this->client->loginUser($this->findUserByEmail($this->entityManager, 'user@example.com'), 'main');
        $this->client->request('GET', '/portal/landlord/self-billing/export');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordExportContainsOnlyOwnInvoices(): void
    {
        $landlord = $this->findUserByEmail($this->entityManager, 'landlord@example.com');
        $landlord2 = $this->findUserByEmail($this->entityManager, 'landlord2@example.com');

        $invoice1 = $this->createInvoice($landlord, 'P001-202504', 4, 2025);
        $invoice2 = $this->createInvoice($landlord2, 'P002-202504', 4, 2025);
        $this->entityManager->persist($invoice1);
        $this->entityManager->persist($invoice2);
        $this->entityManager->flush();

        $this->client->loginUser($landlord, 'main');
        $this->client->request('GET', '/portal/landlord/self-billing/export');

        $body = $this->assertXlsxResponse($this->client);
        $rows = $this->readXlsxRows($body);

        self::assertSame('Číslo faktury', $rows[0][0]);
        self::assertTrue($this->rowsContainCellValue($rows, 'P001-202504'));
        self::assertFalse(
            $this->rowsContainCellValue($rows, 'P002-202504'),
            'Landlord export must not contain another landlord\'s invoice.',
        );
    }

    private function createInvoice(User $landlord, string $invoiceNumber, int $month, int $year): SelfBillingInvoice
    {
        return new SelfBillingInvoice(
            id: Uuid::v7(),
            landlord: $landlord,
            year: $year,
            month: $month,
            invoiceNumber: $invoiceNumber,
            grossAmount: 100_00,
            commissionRate: '0.20',
            netAmount: 80_00,
            issuedAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
    }
}
