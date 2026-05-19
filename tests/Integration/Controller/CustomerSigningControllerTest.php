<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomerSigningControllerTest extends WebTestCase
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

    public function testGoPayBranchShowsLockedInMonthlyFromOrderNotStorageRate(): void
    {
        // Storage default 1500 Kč; locked-in 800 Kč. Page must show 800 Kč.
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('800 Kč', $content);
        $this->assertStringNotContainsString('1 500 Kč', $content);
    }

    public function testExternallyPrepaidBranchShowsGreenBannerNoPrice(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::EXTERNAL,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: new \DateTimeImmutable('2026-12-31'),
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('předplacen externě do 31.12.2026', $content);
        $this->assertStringNotContainsString('Měsíční platba', $content);
    }

    public function testFreeBranchShowsGreenBannerNoPrice(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::EXTERNAL,
            firstPaymentPrice: 0,
            storageRate: 150_000,
            individualMonthlyAmount: 0,
            paidThroughDate: null,
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Bezplatný pronájem', $content);
        $this->assertStringNotContainsString('Měsíční platba', $content);
    }

    private function makeSigningOrder(
        PaymentMethod $paymentMethod,
        int $firstPaymentPrice,
        int $storageRate,
        ?int $individualMonthlyAmount,
        ?\DateTimeImmutable $paidThroughDate,
    ): Order {
        // Find an available unowned storage we can mutate the rate on.
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :reserved')
            ->setParameter('reserved', OrderStatus::RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        $storage = $order->storage;
        $reflection = new \ReflectionClass($storage->storageType);
        $reflection->getProperty('defaultPricePerMonth')->setValue($storage->storageType, $storageRate);

        $orderReflection = new \ReflectionClass($order);
        $orderReflection->getProperty('firstPaymentPrice')->setValue($order, $firstPaymentPrice);

        $order->markAsAdminCreated();
        $order->setPaymentMethod($paymentMethod);
        $order->setOnboardingBillingTerms($individualMonthlyAmount, $paidThroughDate);
        $order->setSigningToken(str_repeat('a', 64));
        $order->extendExpiration(new \DateTimeImmutable('+30 days'));

        $this->entityManager->flush();

        return $order;
    }
}
