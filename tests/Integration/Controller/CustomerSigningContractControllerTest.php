<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerSigningContractControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private string $contractsDir;

    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $this->contractsDir = $projectDir.'/var/contracts';
        if (!is_dir($this->contractsDir)) {
            mkdir($this->contractsDir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->createdFiles = [];
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testServesUploadedContractInline(): void
    {
        $bytes = "%PDF-1.4\n%fake test contract\n";
        $path = $this->contractsDir.'/contract_test_'.bin2hex(random_bytes(6)).'.pdf';
        file_put_contents($path, $bytes);
        $this->createdFiles[] = $path;

        $order = $this->makeSigningOrderWithContract($path);

        $this->client->request('GET', '/podpis/'.$order->signingToken.'/smlouva');

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame($bytes, file_get_contents($response->getFile()->getPathname()));
    }

    public function test404WhenNoUploadedContract(): void
    {
        $order = $this->makeSigningOrderWithContract(null);

        $this->client->request('GET', '/podpis/'.$order->signingToken.'/smlouva');

        $this->assertResponseStatusCodeSame(404);
    }

    public function test404OnUnknownToken(): void
    {
        $this->client->request('GET', '/podpis/'.str_repeat('b', 64).'/smlouva');

        $this->assertResponseStatusCodeSame(404);
    }

    private function makeSigningOrderWithContract(?string $contractPath): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :reserved')
            ->setParameter('reserved', OrderStatus::RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        $order->markAsAdminCreated();
        $order->setSigningToken(str_repeat('a', 64));
        $order->extendExpiration(new \DateTimeImmutable('+30 days'));
        if (null !== $contractPath) {
            $order->setUploadedContractDocumentPath($contractPath);
        }

        $this->entityManager->flush();

        return $order;
    }
}
