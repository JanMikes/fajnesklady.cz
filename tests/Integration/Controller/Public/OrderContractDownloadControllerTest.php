<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contract download happy-path requires LibreOffice (DocumentPdfConverter)
 * which is environment-dependent. We exercise the gating logic (status,
 * UUID validation, missing artefacts) — the actual DOCX→PDF conversion
 * is covered by SendContractReadyEmailHandlerTest at the unit level.
 */
class OrderContractDownloadControllerTest extends WebTestCase
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

    public function testNonCompletedOrderReturns404(): void
    {
        $reserved = $this->findOrderByStatus(OrderStatus::RESERVED);

        $this->client->request('GET', $this->buildUrl($reserved));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidUuidReturns404(): void
    {
        $this->client->request('GET', '/objednavka/not-a-uuid/dokumenty/smlouva.pdf');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testNonexistentOrderReturns404(): void
    {
        $this->client->request('GET', '/objednavka/'.Uuid::v7()->toRfc4122().'/dokumenty/smlouva.pdf');

        $this->assertResponseStatusCodeSame(404);
    }

    private function buildUrl(Order $order): string
    {
        return '/objednavka/'.$order->id->toRfc4122().'/dokumenty/smlouva.pdf';
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('No order with status %s in fixtures', $status->value));

        return $order;
    }
}
