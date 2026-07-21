<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\AllocationStepType;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\StorageStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 089: the debt page must offer BOTH payment methods on every order,
 * regardless of the order's own paymentMethod. The regression this guards is a
 * GoPay-method order rendering no account number / VS / QR at all.
 */
final class OrderDebtPaymentControllerTest extends WebTestCase
{
    private const int DEBT_IN_HALER = 350_000;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testGoPayOrderStillOffersBankTransferDetails(): void
    {
        $order = $this->createSignedDebtOrder(PaymentMethod::GOPAY);

        $crawler = $this->client->request('GET', $this->debtUrl($order));

        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        $this->assertStringContainsString('Zaplatit bankovním převodem', $html);
        $this->assertStringContainsString('Zaplatit kartou online', $html);

        // The bank details a customer needs in order to wire the debt.
        $this->assertNotNull($order->variableSymbol);
        $this->assertStringContainsString($order->variableSymbol, $html);
        $this->assertStringContainsString('2603478520', $html);
        $this->assertSelectorExists('img[alt="QR platba"]');

        // …and the card gateway is still available on the same page.
        $this->assertSelectorExists('#pay-button');
    }

    public function testBankTransferOrderStillOffersCardGateway(): void
    {
        $order = $this->createSignedDebtOrder(PaymentMethod::BANK_TRANSFER);

        $crawler = $this->client->request('GET', $this->debtUrl($order));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#pay-button');

        $html = $crawler->html();
        $this->assertStringContainsString('Zaplatit bankovním převodem', $html);

        // The customer's own billing track leads.
        $this->assertLessThan(
            strpos($html, 'Zaplatit kartou online'),
            strpos($html, 'Zaplatit bankovním převodem'),
            'Bank block must render before the card block for a bank-transfer order.',
        );
    }

    public function testGoPayOrderRendersCardBlockFirst(): void
    {
        $order = $this->createSignedDebtOrder(PaymentMethod::GOPAY);

        $crawler = $this->client->request('GET', $this->debtUrl($order));

        $html = $crawler->html();
        $this->assertLessThan(
            strpos($html, 'Zaplatit bankovním převodem'),
            strpos($html, 'Zaplatit kartou online'),
            'Card block must render before the bank block for a GoPay order.',
        );
    }

    public function testPartiallyWiredDebtShowsRemainderNotOriginalAmount(): void
    {
        $order = $this->createSignedDebtOrder(PaymentMethod::GOPAY);
        $this->recordDebtAllocation($order, 100_000);

        $crawler = $this->client->request('GET', $this->debtUrl($order));

        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        $this->assertStringContainsString('Část dluhu už evidujeme jako uhrazenou', $html);
        // 350 000 haléřů debt − 100 000 wired = 2 500 Kč outstanding.
        $this->assertStringContainsString('2 500', $html);
    }

    public function testUnsignedOrderIsNotFound(): void
    {
        $order = $this->createSignedDebtOrder(PaymentMethod::GOPAY, sign: false);

        $this->client->request('GET', $this->debtUrl($order));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testOrderWithoutDebtRedirectsAway(): void
    {
        $order = $this->createSignedDebtOrder(PaymentMethod::GOPAY, debt: false);

        $this->client->request('GET', $this->debtUrl($order));

        $this->assertResponseRedirects();
    }

    public function testUnknownOrderIsNotFound(): void
    {
        $this->client->request('GET', '/objednavka/'.Uuid::v7()->toRfc4122().'/platba/dluh');

        $this->assertResponseStatusCodeSame(404);
    }

    private function debtUrl(Order $order): string
    {
        return sprintf('/objednavka/%s/platba/dluh', $order->id->toRfc4122());
    }

    private function createSignedDebtOrder(
        PaymentMethod $paymentMethod,
        bool $sign = true,
        bool $debt = true,
    ): Order {
        $storage = $this->findAvailableStorage();
        $user = $this->findTenant();

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+1 day'),
            endDate: $now->modify('+6 months'),
            firstPaymentPrice: 150_000,
            expiresAt: $now->modify('+30 days'),
            createdAt: $now,
        );
        $order->setPaymentMethod($paymentMethod);
        $order->assignVariableSymbol((string) random_int(1_000_000_000, 9_999_999_999));

        if ($debt) {
            $order->setOnboardingDebt(self::DEBT_IN_HALER);
        }

        if ($sign) {
            $order->acceptTerms($now);
            $order->attachSignature(
                signaturePath: '/tmp/test_signature.png',
                signingMethod: \App\Enum\SigningMethod::DRAW,
                typedName: null,
                styleId: null,
                signingPlace: 'Praha',
                now: $now,
            );
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Under spec 091 a partial payment is recorded as a typed allocation — that is
     * what makes debt money and first-payment money separate pools.
     */
    private function recordDebtAllocation(Order $order, int $amountInHaler): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $bankTx = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: (string) random_int(1_000_000, 9_999_999),
            amount: $amountInHaler,
            currency: 'CZK',
            variableSymbol: $order->variableSymbol,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Novak',
            transactionDate: $now->modify('-2 hours'),
            comment: null,
            createdAt: $now,
        );
        $this->entityManager->persist($bankTx);

        $this->entityManager->persist(new BankTransactionAllocation(
            id: Uuid::v7(),
            bankTransaction: $bankTx,
            order: $order,
            type: AllocationStepType::ONBOARDING_DEBT,
            amountInHaler: $amountInHaler,
            createdAt: $now,
        ));

        $this->entityManager->flush();
    }

    private function findTenant(): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::TENANT_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($user instanceof User, 'Tenant fixture user not found');

        return $user;
    }

    private function findAvailableStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->join('s.place', 'p')
            ->join('s.storageType', 'st')
            ->where('s.status = :status')
            ->andWhere('p.isActive = true')
            ->andWhere('st.isActive = true')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage, 'No available storage found in fixtures');

        return $storage;
    }
}
