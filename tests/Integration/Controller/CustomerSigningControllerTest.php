<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CustomerSigningControllerTest extends WebTestCase
{
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

    public function testGoPayBranchShowsLockedInMonthlyFromOrderNotStorageRate(): void
    {
        // Storage default 1500 Kč; locked-in 800 Kč. Page must show 800 Kč.
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('800 Kč', $content);
        $this->assertStringNotContainsString('1 500 Kč', $content);
    }

    public function testExternallyPrepaidBranchShowsFutureBillingStoryNoLogos(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::EXTERNAL,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: new \DateTimeImmutable('2026-12-31'),
            billingMode: BillingMode::MANUAL_RECURRING,
            endDate: new \DateTimeImmutable('2027-12-31'),
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('předplacen externě do 31.12.2026', $content);
        // The customer must see what comes after the prepaid window (spec 085).
        $this->assertStringContainsString('01.01.2027', $content);
        $this->assertStringContainsString('800 Kč / měsíc', $content);
        $this->assertStringContainsString('QR kódem pro bankovní převod', $content);
        $this->assertStringContainsString('Měsíční platba od 01.01.2027', $content);
        $this->assertStringNotContainsString('kontaktujeme', $content);
        // No payment surface for prepaid: no in-page card logos, no recurring consent.
        // (The footer always renders payment_logos, so key on the in-page SSL note instead.)
        $this->assertStringNotContainsString('Vaše platba je zabezpečena 256-bit SSL/TLS', $content);
        $this->assertStringNotContainsString('Parametry opakované platby', $content);
    }

    public function testExternallyPrepaidCoveringWholeTermShowsNoPaymentStory(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::EXTERNAL,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: new \DateTimeImmutable('2027-12-31'),
            billingMode: BillingMode::MANUAL_RECURRING,
            endDate: new \DateTimeImmutable('2027-12-31'),
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('žádné platby nečekají', $content);
        $this->assertStringNotContainsString('Měsíční platba od', $content);
        $this->assertStringNotContainsString('QR kódem', $content);
    }

    public function testFreeBranchShowsGreenBannerNoPrice(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::EXTERNAL,
            firstPaymentPrice: 0,
            storageRate: 150_000,
            individualMonthlyAmount: 0,
            paidThroughDate: null,
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Bezplatný pronájem', $content);
        $this->assertStringNotContainsString('Měsíční platba', $content);
    }

    public function testScenarioBRendersContractText(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Podpis smlouvy', $content);
        $this->assertStringContainsString('I. Předmět smlouvy', $content);
        $this->assertStringNotContainsString('Přijetí a podpis obchodních podmínek', $content);
        $this->assertStringNotContainsString('Vaše smlouva', $content);
    }

    public function testScenarioARendersUploadedContractPreview(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            uploadedContractPath: '/var/contracts/contract_test.pdf',
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Přijetí a podpis obchodních podmínek', $content);
        $this->assertStringContainsString('Vaše smlouva', $content);
        $this->assertStringContainsString('/podpis/'.$order->signingToken.'/smlouva', $content);
        $this->assertStringNotContainsString('I. Předmět smlouvy', $content);
    }

    public function testGoPayAutoRecurringShowsRecurringConsentAndLogos(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            billingMode: BillingMode::AUTO_RECURRING,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2026-08-01'),
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Parametry opakované platby', $content);
        $this->assertStringContainsString('accept_recurring_payments', $content);
        $this->assertStringContainsString('PCI-DSS', $content);
        // In-page card logos (distinct from the always-present footer logos).
        $this->assertStringContainsString('Vaše platba je zabezpečena 256-bit SSL/TLS', $content);
    }

    public function testBankTransferShowsManualInfoNoLogos(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::BANK_TRANSFER,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            billingMode: BillingMode::MANUAL_RECURRING,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2026-08-01'),
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('Ručně schvalovaná platba', $content);
        // Bank transfer is not a card surface: no in-page card logos, no AUTO recurring consent.
        $this->assertStringNotContainsString('Vaše platba je zabezpečena 256-bit SSL/TLS', $content);
        $this->assertStringNotContainsString('Parametry opakované platby', $content);
    }

    public function testEarlyStartWaiverShownForScenarioBNearStart(): void
    {
        // MockClock is 2025-06-15; a start within 14 days requires the waiver (scenario B).
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-06-27'),
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringContainsString('ztrácím právo odstoupit', $content);
    }

    public function testEarlyStartWaiverAbsentForUploadedContract(): void
    {
        // Same near start, but uploaded-contract onboarding skips the waiver (handled on paper).
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-06-27'),
            uploadedContractPath: '/var/contracts/contract_test.pdf',
        );

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        \assert(is_string($content));
        $this->assertStringNotContainsString('ztrácím právo odstoupit', $content);
    }

    public function testPostMissingConsumerNoticeIsRejected(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2025-08-15'),
        );

        $this->client->request('POST', '/podpis/'.$order->signingToken, [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_operating_rules' => '1',
            // accept_consumer_notice intentionally omitted
            'accept_gdpr' => '1',
            'signature_consent' => '1',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
            'signing_method' => 'draw',
            'signing_place' => 'Brno',
        ]);

        // Validation error re-renders (200); success would 302 to the payment page.
        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($this->reloadOrder($order->id)->hasSignature());
    }

    public function testPostMissingRecurringConsentRejectedForAutoGoPay(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
            billingMode: BillingMode::AUTO_RECURRING,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2026-08-01'),
        );

        $this->client->request('POST', '/podpis/'.$order->signingToken, [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_operating_rules' => '1',
            'accept_consumer_notice' => '1',
            'accept_gdpr' => '1',
            'signature_consent' => '1',
            // accept_recurring_payments intentionally omitted
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
            'signing_method' => 'draw',
            'signing_place' => 'Brno',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertFalse($this->reloadOrder($order->id)->hasSignature());
    }

    public function testAlreadySignedOrderRedirectsWithoutReprocessing(): void
    {
        $order = $this->makeSigningOrder(
            paymentMethod: PaymentMethod::GOPAY,
            firstPaymentPrice: 80_000,
            storageRate: 150_000,
            individualMonthlyAmount: 80_000,
            paidThroughDate: null,
        );

        // Simulate a prior successful sign that left a signature while the token is still present
        // (the concurrent-double-submit window). A second POST must not re-process the signing.
        $order->attachSignature(
            signaturePath: 'signatures/already-signed.png',
            signingMethod: SigningMethod::DRAW,
            typedName: null,
            styleId: null,
            signingPlace: 'Praha',
            now: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
        $this->entityManager->flush();

        $this->client->request('POST', '/podpis/'.$order->signingToken, [
            'accept_contract' => '1',
            'accept_vop' => '1',
            'accept_gdpr' => '1',
            'signature_consent' => '1',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
            'signing_method' => 'draw',
            'signing_place' => 'Brno',
        ]);

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/objednavka/'.$order->id->toRfc4122().'/platba', $location);
    }

    private function reloadOrder(\Symfony\Component\Uid\Uuid $id): Order
    {
        $this->entityManager->clear();
        $order = $this->entityManager->find(Order::class, $id);
        \assert($order instanceof Order);

        return $order;
    }

    public function testAwaitingPaymentChoiceRedirectsToChoiceStep(): void
    {
        // Spec 088: a deferred onboarding cannot be shown the signing page until
        // the customer has locked their payment method + frequency.
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :reserved')
            ->setParameter('reserved', OrderStatus::RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order);

        $order->markCustomerChoosesPayment();
        (new \ReflectionClass($order))->getProperty('paymentMethod')->setValue($order, null);
        $order->setSigningToken(str_repeat('c', 64));
        $order->extendExpiration(new \DateTimeImmutable('+30 days'));
        $this->entityManager->flush();

        $this->client->request('GET', '/podpis/'.$order->signingToken);

        $this->assertResponseRedirects('/podpis/'.$order->signingToken.'/zpusob-platby');
    }

    private function makeSigningOrder(
        PaymentMethod $paymentMethod,
        int $firstPaymentPrice,
        int $storageRate,
        ?int $individualMonthlyAmount,
        ?\DateTimeImmutable $paidThroughDate,
        BillingMode $billingMode = BillingMode::AUTO_RECURRING,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
        ?string $uploadedContractPath = null,
    ): Order {
        // Find the single available RESERVED order we can safely mutate (DAMA rolls back per test).
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :reserved')
            ->setParameter('reserved', OrderStatus::RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        $storage = $order->storage;
        $typeReflection = new \ReflectionClass($storage->storageType);
        $typeReflection->getProperty('defaultPricePerMonth')->setValue($storage->storageType, $storageRate);

        $orderReflection = new \ReflectionClass($order);
        $orderReflection->getProperty('firstPaymentPrice')->setValue($order, $firstPaymentPrice);
        if (null !== $startDate) {
            $orderReflection->getProperty('startDate')->setValue($order, $startDate);
        }
        if (null !== $endDate) {
            $orderReflection->getProperty('endDate')->setValue($order, $endDate);
        }

        $order->markAsAdminCreated();
        $order->setPaymentMethod($paymentMethod);
        $order->setBillingMode($billingMode);
        $order->setOnboardingBillingTerms($individualMonthlyAmount, $paidThroughDate);
        if (null !== $uploadedContractPath) {
            $order->setUploadedContractDocumentPath($uploadedContractPath);
        }
        $order->setSigningToken(str_repeat('a', 64));
        $order->extendExpiration(new \DateTimeImmutable('+30 days'));

        $this->entityManager->flush();

        return $order;
    }
}
