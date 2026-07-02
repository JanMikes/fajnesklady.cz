<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

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

class AdminFineCreateControllerTest extends WebTestCase
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
        $contract = $this->createContract();
        $this->entityManager->flush();

        $this->client->request('GET', $this->url($contract));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdminUser(): void
    {
        $contract = $this->createContract();
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('GET', $this->url($contract));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testFormUsesKorunyLabelsNotHaler(): void
    {
        $contract = $this->createContract();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', $this->url($contract));

        $this->assertResponseIsSuccessful();
        $html = $crawler->html();
        $this->assertStringContainsString('Částka (Kč)', $html);
        $this->assertStringContainsString('Základ dluhu (Kč)', $html);
        $this->assertStringNotContainsString('haléř', $html);
        // koruny inputs expose a decimal numeric keypad on mobile
        $this->assertStringContainsString('inputmode="decimal"', $html);
    }

    public function testSubmittingKorunyPersistsHaler(): void
    {
        $contract = $this->createContract();
        $this->entityManager->flush();
        $contractId = $contract->id;

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'fine_form' => [
                'type' => FineType::DIRTY_STORAGE->value,
                'amountInCzk' => '6000',
                'description' => 'Znečištěná skladová jednotka',
            ],
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $fine = $this->findFineForContract($contractId);
        $this->assertSame(600000, $fine->amountInHaler);
    }

    public function testSubmittingDecimalKorunyRoundsToHaler(): void
    {
        $contract = $this->createContract();
        $this->entityManager->flush();
        $contractId = $contract->id;

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'fine_form' => [
                'type' => FineType::OTHER->value,
                // 12.345 Kč -> 1234.5 haléřů; (int) round() => 1235
                'amountInCzk' => '12.345',
                'description' => 'Hraniční zaokrouhlení',
            ],
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $fine = $this->findFineForContract($contractId);
        $this->assertSame(1235, $fine->amountInHaler);
    }

    public function testRejectsNonPositiveAmount(): void
    {
        $contract = $this->createContract();
        $this->entityManager->flush();
        $contractId = $contract->id;

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'fine_form' => [
                'type' => FineType::OTHER->value,
                'amountInCzk' => '0',
                'description' => 'Neplatná částka',
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.form-error', 'musí být kladná');
        $this->assertNull($this->findFineForContractOrNull($contractId));
    }

    private function url(Contract $contract): string
    {
        return '/portal/admin/pokuty/vytvorit/'.$contract->id->toRfc4122();
    }

    private function loginAsAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
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

    private function findFineForContract(Uuid $contractId): Fine
    {
        $fine = $this->findFineForContractOrNull($contractId);
        \assert($fine instanceof Fine);

        return $fine;
    }

    private function findFineForContractOrNull(Uuid $contractId): ?Fine
    {
        $this->entityManager->clear();

        $fine = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(Fine::class, 'f')
            ->where('f.contract = :contract')
            ->setParameter('contract', $contractId->toRfc4122())
            ->getQuery()
            ->getOneOrNullResult();
        \assert(null === $fine || $fine instanceof Fine);

        return $fine;
    }

    private function createContract(): Contract
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Fine-test place',
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
            name: 'Fine-test type',
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
            number: 'FINE',
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
