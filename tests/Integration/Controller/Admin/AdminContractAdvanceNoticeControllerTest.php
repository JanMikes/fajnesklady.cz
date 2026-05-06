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
use App\Enum\RentalType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminContractAdvanceNoticeControllerTest extends WebTestCase
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
        $contract = $this->createRecurringContract();
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($contract));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        $contract = $this->createRecurringContract();
        $this->entityManager->flush();

        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $this->client->request('POST', $this->url($contract));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRejectsAmountAboveLegalMax(): void
    {
        $contract = $this->createRecurringContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'new_amount_czk' => '15001',
            'admin_note' => '',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $session = $this->client->getRequest()->getSession();
        $flashes = $session->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);
        $this->assertStringContainsString('15 000', (string) $flashes[0]);
    }

    public function testRejectsWhenBothFieldsEmpty(): void
    {
        $contract = $this->createRecurringContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'new_amount_czk' => '',
            'admin_note' => '',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);
    }

    public function testAcceptsValidAmount(): void
    {
        $contract = $this->createRecurringContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'new_amount_czk' => '3500',
            'admin_note' => '',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('success');
        $this->assertNotEmpty($flashes);
    }

    public function testRejectsForContractWithoutActiveRecurring(): void
    {
        $contract = $this->createRecurringContract(activate: false);
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'new_amount_czk' => '3500',
            'admin_note' => 'note',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);
    }

    private function url(Contract $contract): string
    {
        return '/portal/admin/contracts/'.$contract->id->toRfc4122().'/advance-notice';
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

    private function createRecurringContract(bool $activate = true): Contract
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Adv-test place',
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
            name: 'Adv-test type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: $now,
        );
        $this->entityManager->persist($storageType);

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'ADV',
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
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('-2 months'),
            endDate: null,
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
            rentalType: RentalType::UNLIMITED,
            startDate: $now->modify('-2 months'),
            endDate: null,
            createdAt: $now->modify('-2 months'),
        );
        if ($activate) {
            $contract->setRecurringPayment(
                'gp-parent-adv',
                $now->modify('+30 days'),
                $now->modify('+30 days'),
            );
        }
        $this->entityManager->persist($contract);

        return $contract;
    }
}
