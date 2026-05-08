<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\DataFixtures\OrderFixtures;
use App\Entity\Contract;
use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\ContractRepository;
use App\Service\OrderStatusUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class OrderStatusControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private OrderStatusUrlGenerator $urlGenerator;
    private ContractRepository $contractRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->urlGenerator = $container->get(OrderStatusUrlGenerator::class);
        $this->contractRepository = $container->get(ContractRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testUnsignedRequestReturns403(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::COMPLETED);

        $this->client->request('GET', '/objednavka/'.$order->id->toRfc4122().'/stav');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testTamperedSignatureReturns403(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::COMPLETED);
        $signed = $this->urlGenerator->generate($order);
        $tampered = preg_replace('/_hash=[^&]+/', '_hash=tampered', $signed);
        \assert(is_string($tampered));

        $this->requestSigned($tampered);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCompletedOrderRendersActiveBadge(): void
    {
        // Pin to REF_ORDER_COMPLETED — its contract is active (not terminated),
        // so the resolver must return the "Aktivní" case.
        $order = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED);

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Aktivní', $body);
        $this->assertStringContainsString('Vaše dokumenty', $body);
        $this->assertMatchesRegularExpression(
            '~/dokumenty/smlouva\.pdf\?_hash=~',
            $body,
            'Contract download href must be signed.',
        );
    }

    public function testActiveContractRendersAccessCodeBlockWhenStorageHasLockCode(): void
    {
        // REF_ORDER_COMPLETED_UNLIMITED uses storage C1 in Praha Centrum which is
        // seeded with lock code 0577 in fixtures. Contract is active (not terminated).
        $order = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED);

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Váš přístupový kód', $body);
        $this->assertStringContainsString('0577', $body);
    }

    public function testTerminatedContractDoesNotRenderAccessCodeBlock(): void
    {
        // Same fixture as the active-contract test (storage C1 has lockCode "0577")
        // — flip the contract into a terminated state so the partial's gating
        // condition fails. Mutating the fixture inside the test is safe because
        // DAMA DoctrineTestBundle wraps each test in a rollback-only transaction.
        $order = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED);
        $contract = $this->contractRepository->findByOrder($order);
        \assert($contract instanceof Contract, 'Fixture order must have a contract');
        $contract->terminate(
            new \DateTimeImmutable('2025-06-10'),
            \App\Enum\TerminationReason::TENANT_NOTICE,
        );
        $this->entityManager->flush();

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Váš přístupový kód', $body);
        $this->assertStringNotContainsString('0577', $body);
    }

    public function testExpiredContractDoesNotRenderAccessCodeBlock(): void
    {
        // REF_ORDER_COMPLETED → B3 has no lockCode in fixtures, so seed one
        // (still numeric, still 4-digit so the codes-feature path is exercised),
        // then push the contract's endDate into the past to flip the
        // (endDate is null OR endDate >= today) gate.
        $order = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED);
        $contract = $this->contractRepository->findByOrder($order);
        \assert($contract instanceof Contract, 'Fixture order must have a contract');

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $contract->storage->updateLockCode('1234', $now);

        // Reflect a past endDate directly — Contract has no public mutator and
        // ContractFixtures' "expiring soon" already lives on D3 which has no
        // lockCode. This keeps the assertion focused on the partial's gating.
        $reflection = new \ReflectionClass($contract);
        $endDateProp = $reflection->getProperty('endDate');
        $endDateProp->setValue($contract, new \DateTimeImmutable('2025-06-01'));
        $this->entityManager->flush();

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Váš přístupový kód', $body);
        $this->assertStringNotContainsString('1234', $body);
    }

    public function testReservedOrderRendersAwaitingPaymentBadgeAndPayCta(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::RESERVED);

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Čeká na platbu', $body);
        $this->assertStringContainsString('Pokračovat v platbě', $body);
        $this->assertStringContainsString('/objednavka/'.$order->id->toRfc4122().'/platba', $body);
    }

    public function testCancelledOrderRendersCancelledBadge(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::CANCELLED);

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Zrušeno', $body);
        $this->assertStringNotContainsString('Pokračovat v platbě', $body);
        $this->assertStringContainsString('Vytvořit novou objednávku', $body);
    }

    public function testExpiredOrderRendersExpiredBadge(): void
    {
        $order = $this->findOrderByStatus(OrderStatus::EXPIRED);

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Expirováno', $body);
    }

    public function testSwappedOrderIdInSignedUrlReturns403(): void
    {
        // Signature covers the full URL; swapping the id invalidates the hash
        // — confirms that a leaked URL can't be re-pointed at a different order.
        $real = $this->findOrderByStatus(OrderStatus::COMPLETED);
        $signedReal = $this->urlGenerator->generate($real);
        $tampered = str_replace($real->id->toRfc4122(), Uuid::v7()->toRfc4122(), $signedReal);

        $this->requestSigned($tampered);

        $this->assertResponseStatusCodeSame(403);
    }

    private function findOrderByStatus(OrderStatus $status): Order
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['status' => $status]);
        \assert($order instanceof Order, sprintf('No order with status %s in fixtures', $status->value));

        return $order;
    }

    private function findOrderByReference(string $reference): Order
    {
        // ReferenceRepository isn't available here, so we rely on the fixture
        // ID being the only completed order on storage B3 (LIMITED, +29 days)
        // — REF_ORDER_COMPLETED in fixtures/OrderFixtures.php:120-138.
        // Match by the unique combination (B3 storage + LIMITED + active contract).
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', match ($reference) {
                OrderFixtures::REF_ORDER_COMPLETED => 'B3',
                OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED => 'C1',
                default => throw new \LogicException('Unknown reference '.$reference),
            })
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order, sprintf('Order %s not found', $reference));

        return $order;
    }

    public function testFreeContractRendersZdarmaBadge(): void
    {
        $order = $this->findOnboardedOrderByStorageNumber('P2');

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Pronájem zdarma', $body);
        $this->assertStringContainsString('Tato smlouva nepodléhá platbám.', $body);
    }

    public function testExternalPrepaymentInFutureRendersBlueBanner(): void
    {
        $order = $this->findOnboardedOrderByStorageNumber('O2');

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Předplaceno externě do', $body);
        $this->assertStringContainsString('Po tomto datu se obnoví běžné měsíční platby.', $body);
        $this->assertStringNotContainsString('Externí předplatné brzy končí.', $body);
    }

    public function testExternalPrepaymentEndingSoonRendersAmberBanner(): void
    {
        $order = $this->findOnboardedOrderByStorageNumber('E2');

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Externí předplatné brzy končí.', $body);
        $this->assertStringContainsString('simek@fajnesklady.cz', $body);
    }

    public function testVanillaContractDoesNotRenderAnyBillingStatusBanner(): void
    {
        $order = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED);

        $this->requestSigned($this->urlGenerator->generate($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Pronájem zdarma', $body);
        $this->assertStringNotContainsString('Předplaceno externě', $body);
        // Match the customer-billing-status partial wording specifically; the
        // sidebar's existing failed-billing notice already says "Externí" elsewhere
        // — anchor on the unique sentence so this stays a focused regression check.
        $this->assertStringNotContainsString('Externí předplatné brzy končí.', $body);
    }

    private function findOnboardedOrderByStorageNumber(string $storageNumber): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $storageNumber)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order, sprintf('No onboarded order on storage %s', $storageNumber));

        return $order;
    }

    /**
     * Request the signed URL via the test client, preserving the host:port
     * that UriSigner used to compute the hash. Without aligning HTTP_HOST,
     * the request URI rebuilt inside Symfony differs from the signed input
     * and verification fails.
     */
    private function requestSigned(string $absoluteUrl): void
    {
        $parsed = parse_url($absoluteUrl);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $host = ($parsed['host'] ?? 'localhost').(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        $this->client->request('GET', $path.$query, [], [], ['HTTP_HOST' => $host]);
    }
}
