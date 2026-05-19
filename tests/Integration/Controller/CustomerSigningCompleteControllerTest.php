<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomerSigningCompleteControllerTest extends WebTestCase
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

    public function testCompletionPageRendersForCompletedOrder(): void
    {
        $order = $this->findCompletedAdminCreatedOrder();

        $crawler = $this->client->request('GET', '/podpis/dokonceno/'.$order->id);

        $this->assertResponseIsSuccessful();
        // The completion-page CTA must point at the signed status URL —
        // never at /login (admin-onboarded customers are passwordless).
        $ctaHref = $crawler->filter('main a.btn-primary')->attr('href');
        $this->assertNotNull($ctaHref);
        $this->assertStringContainsString('/objednavka/'.$order->id.'/stav', (string) $ctaHref);
        $this->assertStringNotContainsString('/login', (string) $ctaHref);
    }

    public function testCompletionPage404sForUnknownOrder(): void
    {
        $this->client->request('GET', '/podpis/dokonceno/00000000-0000-7000-8000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    private function findCompletedAdminCreatedOrder(): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.isAdminCreated = :true')
            ->andWhere('o.status = :completed')
            ->setParameter('true', true)
            ->setParameter('completed', OrderStatus::COMPLETED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order, 'No admin-created COMPLETED fixture order available — check OnboardingFixtures.');

        return $order;
    }
}
