<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\SigningMethod;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminOrderSendOnboardingReminderControllerTest extends WebTestCase
{
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

    public function testRequiresAuthentication(): void
    {
        $order = $this->createSignedUnpaidAdminOrder();
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        $order = $this->createSignedUnpaidAdminOrder();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('POST', $this->url($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSendPersistsAuditRow(): void
    {
        $order = $this->createSignedUnpaidAdminOrder();
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        // Regression: the audit row used to be persisted AFTER the event-bus
        // dispatch had already flushed, so it was silently lost every time.
        $auditLog = $this->findManualEmailAuditRow($orderId);
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('onboarding_reminder', $auditLog->payload['email_type']);
    }

    public function testNoAuditRowWhenOrderNotEligible(): void
    {
        // Not admin-created, not signed → controller refuses with a flash.
        $order = $this->createOrder();
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);
        $this->assertNull($this->findManualEmailAuditRow($orderId));
    }

    private function url(Order $order): string
    {
        return '/portal/admin/orders/'.$order->id->toRfc4122().'/send-onboarding-reminder';
    }

    private function createSignedUnpaidAdminOrder(): Order
    {
        $now = $this->clock->now();
        $order = $this->createOrder();

        $admin = $this->findUserByEmail('admin@example.com');
        $order->setOnboardingBillingTerms(null, null, $admin);
        $order->attachSignature(
            signaturePath: 'signatures/test.png',
            signingMethod: SigningMethod::DRAW,
            typedName: null,
            styleId: null,
            signingPlace: 'Praha',
            now: $now,
        );

        return $order;
    }

    private function createOrder(): Order
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Onb-reminder place',
            address: 'Testovaci 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $this->entityManager->persist($place);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Onb-reminder type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $now,
        );
        $this->entityManager->persist($storageType);

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'ONB1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $this->entityManager->persist($storage);

        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+7 days'),
            endDate: $now->modify('+7 days')->modify('+6 months'),
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->popEvents();
        $this->entityManager->persist($order);

        return $order;
    }

    private function findManualEmailAuditRow(string $orderId): ?AuditLog
    {
        $this->entityManager->clear();

        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', 'order')
            ->setParameter('eventType', 'admin_manual_email_sent')
            ->setParameter('entityId', $orderId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }
}
