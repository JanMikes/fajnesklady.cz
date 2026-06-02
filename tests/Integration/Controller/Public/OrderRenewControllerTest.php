<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\DataFixtures\OrderFixtures;
use App\Entity\Order;
use App\Service\OrderStatusUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OrderRenewControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private OrderStatusUrlGenerator $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->urlGenerator = $container->get(OrderStatusUrlGenerator::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testRenewPaidLimitedOrderSeedsSessionAndRedirectsToOrderCreate(): void
    {
        $previous = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED);
        $previousUser = $previous->user;

        // Signed link = the guest email flow: works without a session.
        $this->requestSigned($this->urlGenerator->generateRenewal($previous));

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $expectedPrefix = sprintf(
            '/objednavka/%s/%s/%s',
            $previous->storage->place->id->toRfc4122(),
            $previous->storage->storageType->id->toRfc4122(),
            $previous->storage->id->toRfc4122(),
        );
        $this->assertStringContainsString($expectedPrefix, $location);

        $session = $this->client->getRequest()->getSession();
        $sessionData = $session->get('order_form_data');
        $this->assertIsArray($sessionData);
        $this->assertSame($previousUser->email, $sessionData['email']);
        $this->assertSame($previousUser->firstName, $sessionData['firstName']);
        $this->assertSame($previousUser->lastName, $sessionData['lastName']);
        $this->assertSame('limited', $sessionData['rentalType']);
        $this->assertNotNull($sessionData['startDate']);
        $this->assertNotNull($sessionData['endDate']);

        // Continuous renewal: new period starts on previous endDate (still in the future
        // relative to the MockClock 2025-06-15) and lasts the same number of days.
        $expectedStart = $previous->endDate;
        \assert($expectedStart instanceof \DateTimeImmutable);
        $duration = (int) $previous->startDate->diff($expectedStart)->days;
        $expectedEnd = $expectedStart->modify(sprintf('+%d days', $duration));
        $this->assertSame($expectedStart->format('Y-m-d'), $sessionData['startDate']);
        $this->assertSame($expectedEnd->format('Y-m-d'), $sessionData['endDate']);
    }

    public function testRenewUnlimitedOrderSeedsSessionAndRedirectsToOrderCreate(): void
    {
        $previous = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED);

        $this->requestSigned($this->urlGenerator->generateRenewal($previous));

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $expectedPrefix = sprintf(
            '/objednavka/%s/%s/%s',
            $previous->storage->place->id->toRfc4122(),
            $previous->storage->storageType->id->toRfc4122(),
            $previous->storage->id->toRfc4122(),
        );
        $this->assertStringContainsString($expectedPrefix, $location);

        $session = $this->client->getRequest()->getSession();
        $sessionData = $session->get('order_form_data');
        $this->assertIsArray($sessionData);
        $this->assertSame('unlimited', $sessionData['rentalType']);
    }

    public function testRenewCancelledOrderRedirectsToFreshOrderCreateWithoutPrefill(): void
    {
        $previous = $this->findOrderByReference(OrderFixtures::REF_ORDER_CANCELLED);

        $this->requestSigned($this->urlGenerator->generateRenewal($previous));

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/objednavka/'.$previous->storage->place->id->toRfc4122(), $location);

        // Cancelled / never-paid orders fall through to a fresh order — no PII prefill.
        $sessionData = $this->client->getRequest()->getSession()->get('order_form_data');
        $this->assertNull($sessionData);
    }

    public function testOwnerCanRenewWithoutSignature(): void
    {
        // The portal "Prodloužit" button links unsigned; the authenticated owner
        // is entitled to their own data, so the owner path must work.
        $previous = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED);
        $this->client->loginUser($previous->user, 'main');

        $this->client->request('GET', '/objednavka/prodlouzit/'.$previous->id->toRfc4122());

        $this->assertResponseRedirects();
    }

    public function testUnsignedAnonymousRequestIsDenied(): void
    {
        // Real order id, but no signature and not logged in: must NOT leak the
        // previous customer's prefilled PII (name, address, birth date).
        $previous = $this->findOrderByReference(OrderFixtures::REF_ORDER_COMPLETED);

        $this->client->request('GET', '/objednavka/prodlouzit/'.$previous->id->toRfc4122());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnknownOrderIdReturns404(): void
    {
        $this->client->request('GET', '/objednavka/prodlouzit/00000000-0000-7000-8000-000000000000');

        $this->assertResponseStatusCodeSame(404);
    }

    private function findOrderByReference(string $reference): Order
    {
        // Map fixture reference name → known unique storage number on that order.
        // Avoids reaching for the Doctrine fixtures reference repository at runtime.
        $storageByReference = [
            OrderFixtures::REF_ORDER_COMPLETED => 'B3',
            OrderFixtures::REF_ORDER_COMPLETED_UNLIMITED => 'C1',
            OrderFixtures::REF_ORDER_CANCELLED => 'D1',
        ];

        \assert(isset($storageByReference[$reference]), sprintf('No mapping for fixture %s', $reference));

        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $storageByReference[$reference])
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order, sprintf('Fixture order %s not found', $reference));

        return $order;
    }

    /**
     * Request the signed URL via the test client, preserving the host:port that
     * UriSigner used to compute the hash. Without aligning HTTP_HOST, the request
     * URI rebuilt inside Symfony differs from the signed input and verification
     * fails. Mirrors OrderStatusControllerTest::requestSigned().
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
