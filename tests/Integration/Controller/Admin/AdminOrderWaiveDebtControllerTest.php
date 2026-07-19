<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\TerminationReason;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminOrderWaiveDebtControllerTest extends WebTestCase
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

    public function testWaiveFullClearsDebtWithoutPayment(): void
    {
        [$order, $contract] = $this->createTerminatedContractWithDebt(50000);
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();
        $contractId = $contract->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order), [
            'amount' => '500',
            'reason' => 'Nedobytné',
            'password' => 'password',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        $this->assertFalse($reloaded->hasOutstandingDebt());

        // Waive records no money — there must be no Payment row.
        $this->assertNull($this->findPaymentForContract($contractId));
        $this->assertNotNull($this->findAuditRow($contractId, 'debt_waived'));
    }

    public function testWaivePartialLeavesRemainder(): void
    {
        [$order, $contract] = $this->createTerminatedContractWithDebt(50000);
        $this->entityManager->flush();
        $contractId = $contract->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order), [
            'amount' => '150',
            'password' => 'password',
        ]);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        $this->assertSame(35000, $reloaded->outstandingDebtAmount);
    }

    public function testWrongPasswordDoesNotMutate(): void
    {
        [$order, $contract] = $this->createTerminatedContractWithDebt(50000);
        $this->entityManager->flush();
        $contractId = $contract->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order), [
            'amount' => '500',
            'password' => 'wrong-password',
        ]);

        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        $this->assertSame(50000, $reloaded->outstandingDebtAmount);
        $this->assertNull($this->findAuditRow($contractId, 'debt_waived'));
    }

    public function testRejectsAmountAboveDebt(): void
    {
        [$order, $contract] = $this->createTerminatedContractWithDebt(50000);
        $this->entityManager->flush();
        $contractId = $contract->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order), [
            'amount' => '600',
            'password' => 'password',
        ]);

        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        $this->assertSame(50000, $reloaded->outstandingDebtAmount);
    }

    public function testRequiresAuthentication(): void
    {
        [$order] = $this->createTerminatedContractWithDebt(50000);
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($order), ['amount' => '500', 'password' => 'password']);

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdmin(): void
    {
        [$order] = $this->createTerminatedContractWithDebt(50000);
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('POST', $this->url($order), ['amount' => '500', 'password' => 'password']);

        $this->assertResponseStatusCodeSame(403);
    }

    private function url(Order $order): string
    {
        return '/portal/admin/orders/'.$order->id->toRfc4122().'/waive-debt';
    }

    private function loginAsAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
    }

    /**
     * @return array{0: Order, 1: Contract}
     */
    private function createTerminatedContractWithDebt(int $debtInHaler): array
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Waive-test place',
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
            name: 'Waive type',
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
            number: 'WVE1',
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
        if ($debtInHaler > 0) {
            $contract->setOutstandingDebt($debtInHaler);
        }
        $contract->terminate($now, TerminationReason::PAYMENT_FAILURE);
        $contract->popEvents();
        $this->entityManager->persist($contract);

        return [$order, $contract];
    }

    private function findPaymentForContract(string $contractId): ?Payment
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->join('p.contract', 'c')
            ->where('c.id = :contractId')
            ->setParameter('contractId', $contractId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findAuditRow(string $entityId, string $eventType): ?AuditLog
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
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
