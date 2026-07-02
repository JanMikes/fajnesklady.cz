<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\Contract;
use App\Entity\Order;
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

class AdminContractTerminateControllerTest extends WebTestCase
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

    public function testImmediateTerminationAsAdmin(): void
    {
        $contract = $this->createTestContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'reason' => 'Porušení smlouvy',
            'password' => 'password',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('success');
        $this->assertNotEmpty($flashes);

        $this->entityManager->refresh($contract);
        $this->assertTrue($contract->isTerminated());
        $this->assertSame(TerminationReason::ADMIN, $contract->terminationReason);
    }

    public function testWithNoticeTerminationAsAdmin(): void
    {
        $contract = $this->createTestContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'with_notice',
            'reason' => '',
            'password' => 'password',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('success');
        $this->assertNotEmpty($flashes);

        $this->entityManager->refresh($contract);
        $this->assertFalse($contract->isTerminated());
        $this->assertTrue($contract->hasPendingTermination());
        $this->assertNotNull($contract->terminatesAt);
    }

    public function testRejectsWrongPassword(): void
    {
        $contract = $this->createTestContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'wrong-password',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);

        $this->entityManager->refresh($contract);
        $this->assertFalse($contract->isTerminated());
    }

    public function testNonAdminGets403(): void
    {
        $contract = $this->createTestContract();
        $this->entityManager->flush();

        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAlreadyTerminatedContract(): void
    {
        $contract = $this->createTestContract();
        $contract->terminate($this->clock->now(), TerminationReason::EXPIRED);
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);
    }

    public function testInvalidTerminationType(): void
    {
        $contract = $this->createTestContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'invalid',
            'password' => 'password',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);
    }

    public function testRequiresAuthentication(): void
    {
        $contract = $this->createTestContract();
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
        ]);

        $this->assertResponseRedirects('/login');
    }

    private function url(Contract $contract): string
    {
        return '/portal/admin/contracts/'.$contract->id->toRfc4122().'/terminate';
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->findUserByEmail('admin@example.com');
        $this->client->loginUser($admin, 'main');
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

    private function createTestContract(): Contract
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Term-test place',
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
            name: 'Term-test type',
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
            number: 'TRM',
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
        $this->entityManager->persist($contract);

        return $contract;
    }
}
