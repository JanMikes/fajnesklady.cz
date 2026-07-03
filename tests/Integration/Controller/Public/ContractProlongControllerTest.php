<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Contract;
use App\Entity\ContractProlongation;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Repository\PlatformSettingsRepository;
use App\Service\OrderStatusUrlGenerator;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class ContractProlongControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private OrderStatusUrlGenerator $urlGenerator;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->urlGenerator = $container->get(OrderStatusUrlGenerator::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient->reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testSignedAnonymousRequestRendersForm(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);

        $this->requestSigned($this->urlGenerator->generateProlongation($contract));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Prodloužení smlouvy', $body);
        $this->assertStringContainsString('Nové datum konce', $body);
    }

    public function testOwnerCanOpenUnsigned(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
    }

    public function testUnsignedAnonymousRequestIsDenied(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testWrongUserWithoutSignatureIsDenied(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $this->client->loginUser($this->findUser('tenant@example.com'), 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testProlongMovesEndDateAndWritesAuditTrail(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $previousEnd = $contract->endDate;
        $newEnd = $previousEnd->modify('+3 months');
        $this->client->loginUser($contract->user, 'main');

        $this->submitProlong($contract, $newEnd, 'keep');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Smlouva prodloužena', $body);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        $this->assertEquals($newEnd->format('Y-m-d'), $refreshed->endDate->format('Y-m-d'));

        $prolongations = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(ContractProlongation::class, 'p')
            ->where('p.contract = :contract')
            ->setParameter('contract', $refreshed)
            ->getQuery()
            ->getResult();
        $this->assertCount(1, $prolongations);
        $this->assertEquals($previousEnd->format('Y-m-d'), $prolongations[0]->previousEndDate->format('Y-m-d'));
        $this->assertEquals($newEnd->format('Y-m-d'), $prolongations[0]->newEndDate->format('Y-m-d'));
    }

    public function testProlongIsBlockedByThirdPartyBookingAfterEnd(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        // Someone else books the unit starting 10 days after the contract end.
        $this->createBlockingOrder(
            $contract->storage,
            $this->findUser('tenant@example.com'),
            $contract->endDate->modify('+10 days'),
            $contract->endDate->modify('+70 days'),
        );
        $this->client->loginUser($contract->user, 'main');

        // The form clamps the max; a POST beyond it must be rejected server-side.
        $this->submitProlong($contract, $contract->endDate->modify('+2 months'), 'keep');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Zvolte platné datum konce', $body);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        $this->assertNotEquals(
            $contract->endDate->modify('+2 months')->format('Y-m-d'),
            $refreshed->endDate->format('Y-m-d'),
        );
    }

    public function testProlongBlockedEntirelyWhenUnitTakenRightAfterEnd(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $this->createBlockingOrder(
            $contract->storage,
            $this->findUser('tenant@example.com'),
            $contract->endDate->modify('+1 day'),
            $contract->endDate->modify('+40 days'),
        );
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('rezervovanou někdo jiný', $body);
        $this->assertStringContainsString('Vytvořit novou objednávku', $body);
    }

    public function testCardContractProlongsWithUnboundedHorizonAndKeepsToken(): void
    {
        $contract = $this->createActiveContract(BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, tokenId: 'gp_prolong_keep');
        $newEnd = $contract->endDate->modify('+2 years');
        $this->client->loginUser($contract->user, 'main');

        $this->submitProlong($contract, $newEnd, 'keep');

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        $this->assertEquals($newEnd->format('Y-m-d'), $refreshed->endDate->format('Y-m-d'));
        $this->assertSame('gp_prolong_keep', $refreshed->goPayParentPaymentId, 'Token must survive the prolongation.');
        $this->assertFalse($this->goPayClient->wasRecurrenceVoided('gp_prolong_keep'));
    }

    public function testCardToBankSwitchVoidsTokenAndAssignsVariableSymbol(): void
    {
        $contract = $this->createActiveContract(BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, tokenId: 'gp_prolong_switch');
        $this->client->loginUser($contract->user, 'main');

        $this->submitProlong($contract, $contract->endDate->modify('+3 months'), 'bank');

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        $this->assertNull($refreshed->goPayParentPaymentId);
        $this->assertSame(BillingMode::MANUAL_RECURRING, $refreshed->billingMode);
        $this->assertNotNull($refreshed->nextBillingDate, 'Manual cycles must resume after the switch.');
        $this->assertNotNull($refreshed->order->variableSymbol);
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $refreshed->order->paymentMethod);
        $this->assertTrue($this->goPayClient->wasRecurrenceVoided('gp_prolong_switch'));
    }

    public function testBankSwitchSurchargeNoticeShownWhenConfigured(): void
    {
        $settings = static::getContainer()->get(PlatformSettingsRepository::class)->getSettings();
        $settings->updateSurcharge(10_000, $this->clock->now());
        $this->entityManager->flush();

        $contract = $this->createActiveContract(BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, tokenId: 'gp_surcharge_shown');
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Přejít na bankovní převod', $body);
        $this->assertStringContainsString('je účtován příplatek', $body);
        $this->assertStringContainsString('100 Kč za každé fakturační období', $body);
    }

    public function testBankSwitchSurchargeNoticeHiddenWhenZero(): void
    {
        $settings = static::getContainer()->get(PlatformSettingsRepository::class)->getSettings();
        $settings->updateSurcharge(0, $this->clock->now());
        $this->entityManager->flush();

        $contract = $this->createActiveContract(BillingMode::AUTO_RECURRING, PaymentMethod::GOPAY, tokenId: 'gp_surcharge_zero');
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Přejít na bankovní převod', $body);
        $this->assertStringNotContainsString('příplatek', $body);
        $this->assertStringContainsString('Negarantujeme dostupnost', $body);
    }

    public function testOneTimeContractConvertsToManualOnProlong(): void
    {
        $contract = $this->createActiveContract(
            BillingMode::ONE_TIME,
            PaymentMethod::BANK_TRANSFER,
            startDate: $this->clock->now()->modify('-5 days'),
            endDate: $this->clock->now()->modify('+15 days'),
        );
        $previousEnd = $contract->endDate;
        $this->client->loginUser($contract->user, 'main');

        $this->submitProlong($contract, $previousEnd->modify('+2 months'), 'keep');

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        $this->assertSame(BillingMode::MANUAL_RECURRING, $refreshed->billingMode);
        $this->assertEquals($previousEnd->format('Y-m-d'), $refreshed->nextBillingDate?->format('Y-m-d'), 'Extension cycle is due where the one-shot payment ended.');
        $this->assertNotNull($refreshed->order->variableSymbol);
    }

    public function testUpfrontContractWithOutstandingTrancheKeepsAnchorOnProlong(): void
    {
        // Spec 078 tranches: a > 12-month upfront contract mid-tranche has a
        // live nextBillingDate + paidThroughDate. Prolongation converts it to
        // the manual track WITHOUT resetting the anchor — otherwise the unpaid
        // remainder of the rental would be silently marked as paid.
        $contract = $this->createActiveContract(
            BillingMode::ONE_TIME,
            PaymentMethod::BANK_TRANSFER,
            startDate: $this->clock->now()->modify('-5 days'),
            endDate: $this->clock->now()->modify('+15 months'),
        );
        $trancheAnchor = $this->clock->now()->modify('-5 days')->modify('+12 months')->setTime(0, 0);
        $contract->scheduleNextBilling($trancheAnchor, $trancheAnchor);
        $this->entityManager->flush();
        $this->client->loginUser($contract->user, 'main');

        $this->submitProlong($contract, $contract->endDate->modify('+2 months'), 'keep');

        $this->assertResponseIsSuccessful();
        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        $this->assertSame(BillingMode::MANUAL_RECURRING, $refreshed->billingMode);
        $this->assertSame(
            $trancheAnchor->format('Y-m-d'),
            $refreshed->nextBillingDate?->format('Y-m-d'),
            'Outstanding-tranche anchor must survive the prolongation conversion.',
        );
        $this->assertSame(
            $trancheAnchor->format('Y-m-d'),
            $refreshed->paidThroughDate?->format('Y-m-d'),
            'paidThroughDate must keep reflecting what the customer actually paid.',
        );
    }

    public function testTerminatedContractShowsEndedStateWithRenewCta(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $contract->terminate($this->clock->now());
        $this->entityManager->flush();
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Smlouvu již nelze prodloužit', $body);
        $this->assertStringContainsString('/objednavka/prodlouzit/', $body);
    }

    public function testDeactivatedPlaceBlocksProlongation(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $contract->storage->getPlace()->deactivate($this->clock->now());
        $this->entityManager->flush();
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Pobočka již není v provozu', $body);
    }

    public function testContractInArrearsIsBlocked(): void
    {
        $contract = $this->createActiveContract(BillingMode::MANUAL_RECURRING, PaymentMethod::BANK_TRANSFER);
        $contract->recordFailedBillingAttempt($this->clock->now());
        $this->entityManager->flush();
        $this->client->loginUser($contract->user, 'main');

        $this->client->request('GET', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit');

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Prodloužení zatím není možné', $body);
    }

    private function submitProlong(Contract $contract, \DateTimeImmutable $newEndDate, string $paymentChoice): void
    {
        $this->client->request('POST', '/smlouva/'.$contract->id->toRfc4122().'/prodlouzit', [
            'new_end_date' => $newEndDate->format('Y-m-d'),
            'payment_choice' => $paymentChoice,
        ]);
    }

    private function createActiveContract(
        BillingMode $billingMode,
        PaymentMethod $paymentMethod,
        ?string $tokenId = null,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
    ): Contract {
        $now = $this->clock->now();
        $startDate ??= $now->modify('-30 days');
        $endDate ??= $now->modify('+60 days');
        $user = $this->findUser('user@example.com');
        $storage = $this->findStorage('A2');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 50000,
            expiresAt: $startDate->modify('+7 days'),
            createdAt: $startDate,
        );
        $order->setBillingMode($billingMode);
        $order->setPaymentMethod($paymentMethod);
        $order->markPaid($startDate);
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $startDate,
        );
        $contract->applyBillingMode($billingMode);
        $contract->sign($startDate);
        if (null !== $tokenId) {
            $contract->setRecurringPayment($tokenId, $now->modify('+15 days'), $now->modify('+15 days'));
            $this->goPayClient->seedRecurrenceStatus($tokenId, 'PAID', $tokenId, 50000);
        }
        $order->complete($contract->id, $startDate);
        $order->popEvents();
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        self::assertSame(OrderStatus::COMPLETED, $order->status);

        return $contract;
    }

    private function createBlockingOrder(Storage $storage, User $user, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $now = $this->clock->now();
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $start,
            endDate: $end,
            firstPaymentPrice: 50000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->popEvents();
        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function findUser(string $email): User
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

    private function findStorage(string $number): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($storage instanceof Storage);

        return $storage;
    }

    private function requestSigned(string $absoluteUrl): void
    {
        $parsed = parse_url($absoluteUrl);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $host = ($parsed['host'] ?? 'localhost').(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        $this->client->request('GET', $path.$query, [], [], ['HTTP_HOST' => $host]);
    }
}
