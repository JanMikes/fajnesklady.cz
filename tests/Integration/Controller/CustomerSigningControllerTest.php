<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
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

    public function testAlreadySignedOrderRedirectsWithoutReprocessing(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
        );

        // Simulate a prior successful sign that left a signature while the token is still present
        // (the concurrent-double-submit window). A second POST must not re-process the signing.
        $order->attachSignature(
            signaturePath: 'signatures/already-signed.png',
            signingMethod: SigningMethod::DRAW,
            typedName: null,
            styleId: null,
            signingPlace: 'Praha',
            now: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
        $this->entityManager->flush();

        $this->client->request('POST', '/podpis/'.$order->signingToken, [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_gdpr' => '1',
            'signature_consent' => '1',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
            'signing_method' => 'draw',
            'signing_place' => 'Brno',
        ]);

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/objednavka/'.$order->id->toRfc4122().'/platba', $location);
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
