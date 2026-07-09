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
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\SigningMethod;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminOrderRecordExternalPaymentControllerTest extends WebTestCase
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
        [$order] = $this->createRunningContract();
        $this->entityManager->flush();

        $this->client->request('GET', $this->url($order));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        [$order] = $this->createRunningContract();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('GET', $this->url($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testGateBlocksUnsignedOrder(): void
    {
        [$order] = $this->createRunningContract(signed: false);
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('GET', $this->url($order));

        // Ineligible (no signature) → bounced back to the detail page, never the form.
        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);
    }

    public function testFormPageRendersForEligibleOrder(): void
    {
        [$order] = $this->createRunningContract();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('GET', $this->url($order));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Označit jako externě zaplaceno');
    }

    public function testDetailPageShowsBothActionsForEligibleContract(): void
    {
        [$order] = $this->createRunningContract();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        // Exercises the new "Manuální akce" buttons + the extend-deadline modal
        // Twig (canExtendDeadline path with date()/date_modify).
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Označit jako externě zaplaceno');
        $this->assertSelectorTextContains('body', 'Prodloužit splatnost');
    }

    public function testRecordWholeCycleAdvancesContractAndRecordsPayment(): void
    {
        [$order, $contract] = $this->createRunningContract();
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();
        $contractId = $contract->id->toRfc4122();

        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
        $this->client->request('POST', $this->url($order), [
            'external_payment_form' => [
                'coverage' => 'whole_cycle',
                'amountInCzk' => '350',
                'issueInvoice' => '',
            ],
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, Uuid::fromString($contractId));
        $this->assertInstanceOf(Contract::class, $reloaded);
        // Was overdue (due 2 days ago); a whole-cycle payment pushes it forward.
        $this->assertNotNull($reloaded->nextBillingDate);
        $this->assertGreaterThan($this->clock->now(), $reloaded->nextBillingDate);
        $this->assertSame(0, $reloaded->failedBillingAttempts);

        $this->assertNotNull($this->findPaymentForContract($contractId));
        $this->assertNotNull($this->findAuditRow($contractId, 'external_payment_recorded'));
    }

    private function url(Order $order): string
    {
        return '/portal/admin/orders/'.$order->id->toRfc4122().'/record-external-payment';
    }

    /**
     * @return array{0: Order, 1: Contract}
     */
    private function createRunningContract(bool $signed = true): array
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'External-payment place',
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
            name: 'External type',
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
            number: 'EPX1',
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
        if ($signed) {
            $order->attachSignature('signatures/ext.png', SigningMethod::TYPED, 'Jan Novák', null, 'Praha', $now);
        }
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
        $contract->scheduleNextBilling($now->modify('-2 days')->setTime(0, 0), null);
        $contract->popEvents();
        $this->entityManager->persist($contract);

        return [$order, $contract];
    }

    private function findPaymentForContract(string $contractId): ?Payment
    {
        $this->entityManager->clear();

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
