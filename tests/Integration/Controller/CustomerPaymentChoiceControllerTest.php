<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomerPaymentChoiceControllerTest extends WebTestCase
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

    public function testDeferredOrderRendersChoiceForm(): void
    {
        $order = $this->makeOrder(deferred: true);

        $this->client->request('GET', '/podpis/'.$order->signingToken.'/zpusob-platby');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Výběr způsobu platby', $content);
        $this->assertStringContainsString('Bankovní převod', $content);
        // Identification block (compliance).
        $this->assertStringContainsString('Mekmann s.r.o.', $content);
    }

    public function testNonDeferredOrderRedirectsToSigning(): void
    {
        $order = $this->makeOrder(deferred: false);

        $this->client->request('GET', '/podpis/'.$order->signingToken.'/zpusob-platby');

        $this->assertResponseRedirects('/podpis/'.$order->signingToken);
    }

    public function testUnknownTokenShowsError(): void
    {
        $this->client->request('GET', '/podpis/'.str_repeat('f', 64).'/zpusob-platby');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Neplatný odkaz', $content);
    }

    public function testExpiredOrderShowsError(): void
    {
        $order = $this->makeOrder(deferred: true);
        // MockClock is fixed at 2025-06-15; expire the order before that instant.
        $order->extendExpiration(new \DateTimeImmutable('2025-06-01'));
        $this->entityManager->flush();

        $this->client->request('GET', '/podpis/'.$order->signingToken.'/zpusob-platby');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('vypršela', $content);
    }

    public function testCancelledDeferredOrderShowsError(): void
    {
        $order = $this->makeOrder(deferred: true);
        $order->cancel(new \DateTimeImmutable('2025-06-15'));
        $this->entityManager->flush();

        $this->client->request('GET', '/podpis/'.$order->signingToken.'/zpusob-platby');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('již není aktivní', $content);
    }

    private function makeOrder(bool $deferred): Order
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

        $reflection = new \ReflectionClass($order);
        if ($deferred) {
            $order->markCustomerChoosesPayment();
            // A deferred order carries no locked method until the customer chooses.
            $reflection->getProperty('paymentMethod')->setValue($order, null);
        } else {
            $order->setPaymentMethod(PaymentMethod::GOPAY);
        }

        $order->setSigningToken(str_repeat('b', 64));
        $order->extendExpiration(new \DateTimeImmutable('+30 days'));

        $this->entityManager->flush();

        return $order;
    }
}
