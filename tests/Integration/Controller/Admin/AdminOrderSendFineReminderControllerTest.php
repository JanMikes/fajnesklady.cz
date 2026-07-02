<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\FineType;
use App\Enum\PaymentFrequency;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminOrderSendFineReminderControllerTest extends WebTestCase
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
        [$order, $fine] = $this->createOrderWithFine();
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($order, $fine->id->toRfc4122()));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        [$order, $fine] = $this->createOrderWithFine();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('POST', $this->url($order, $fine->id->toRfc4122()));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSendPersistsAuditRow(): void
    {
        [$order, $fine] = $this->createOrderWithFine();
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();
        $fineId = $fine->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order, $fineId));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        // Regression: the audit row used to be persisted AFTER the event-bus
        // dispatch had already flushed, so it was silently lost every time.
        $auditLog = $this->findManualEmailAuditRow($orderId);
        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('fine_reminder', $auditLog->payload['email_type']);
        $this->assertSame($fineId, $auditLog->payload['fine_id']);
    }

    public function testNoAuditRowWhenFineNotFound(): void
    {
        [$order] = $this->createOrderWithFine();
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order, Uuid::v7()->toRfc4122()));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);
        $this->assertNull($this->findManualEmailAuditRow($orderId));
    }

    private function url(Order $order, string $fineId): string
    {
        return '/portal/admin/orders/'.$order->id->toRfc4122().'/send-fine-reminder/'.$fineId;
    }

    /**
     * @return array{0: Order, 1: Fine}
     */
    private function createOrderWithFine(): array
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');
        $admin = $this->findUserByEmail('admin@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Fine-reminder place',
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
            name: 'Fine-reminder type',
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
            number: 'FIN1',
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
            startDate: $now->modify('-2 months'),
            endDate: $now->modify('-2 months')->modify('+12 months'),
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now->modify('-2 months'),
        );
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $tenant,
            storage: $storage,
            startDate: $now->modify('-2 months'),
            endDate: $now->modify('-2 months')->modify('+12 months'),
            createdAt: $now->modify('-2 months'),
        );
        $contract->popEvents();
        $this->entityManager->persist($contract);

        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $tenant,
            issuedBy: $admin,
            type: FineType::LATE_PAYMENT,
            amountInHaler: 50000,
            description: 'Testovací pokuta',
            issuedAt: $now,
            createdAt: $now,
        );
        $fine->popEvents();
        $this->entityManager->persist($fine);

        return [$order, $fine];
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
