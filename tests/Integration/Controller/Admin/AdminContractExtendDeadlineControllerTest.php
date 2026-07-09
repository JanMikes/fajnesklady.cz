<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminContractExtendDeadlineControllerTest extends WebTestCase
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
        [, $contract] = $this->createOverdueManualContract();
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($contract), ['newDeadline' => '2025-06-30']);

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        [, $contract] = $this->createOverdueManualContract();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('POST', $this->url($contract), ['newDeadline' => '2025-06-30']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testExtendSetsGraceAndPersistsAudit(): void
    {
        [$order, $contract] = $this->createOverdueManualContract();
        $this->entityManager->flush();
        $contractId = $contract->id->toRfc4122();
        $orderId = $order->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($contract), ['newDeadline' => '2025-06-30']);

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        $this->assertEquals(new \DateTimeImmutable('2025-06-30'), $reloaded->paymentGraceUntil);
        $this->assertNotNull($this->findAuditRow($contractId, 'payment_deadline_extended'));
    }

    public function testRejectsDeadlineInThePast(): void
    {
        [, $contract] = $this->createOverdueManualContract();
        $this->entityManager->flush();
        $contractId = $contract->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($contract), ['newDeadline' => '2025-06-14']);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        $this->assertNull($reloaded->paymentGraceUntil, 'A past deadline must not set a grace.');
    }

    private function url(Contract $contract): string
    {
        return '/portal/admin/contracts/'.$contract->id->toRfc4122().'/extend-deadline';
    }

    /**
     * @return array{0: Order, 1: Contract}
     */
    private function createOverdueManualContract(): array
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Extend-deadline place',
            address: 'Testovací 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $this->entityManager->persist($place);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Extend type',
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
            number: 'EXT1',
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
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        // Overdue: due two days ago, no grace yet.
        $contract->scheduleNextBilling($now->modify('-2 days')->setTime(0, 0), null);
        $contract->popEvents();
        $this->entityManager->persist($contract);

        return [$order, $contract];
    }

    private function findAuditRow(string $entityId, string $eventType): ?AuditLog
    {
        $this->entityManager->clear();

        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.entityType = :entityType')
            ->andWhere('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('entityType', 'contract')
            ->setParameter('eventType', $eventType)
            ->setParameter('entityId', $entityId)
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
