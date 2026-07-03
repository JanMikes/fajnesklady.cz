<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Repository\PlatformSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Payment-UX pass on the admin onboarding form: "Platební metoda" renders
 * before "Frekvence plateb", GoPay hides the frequency card entirely (card =
 * always automatic monthly recurring, spec 076), EXTERNAL never offers the
 * upfront option (use "Externí předplatné" / "Předplaceno do" instead), the
 * offered radios carry per-option descriptions, and a GoPay submit carrying a
 * stale bank-transfer frequency is normalized to MONTHLY end-to-end.
 *
 * Dates are relative to REAL today: the FormData callbacks compare against
 * `new \DateTimeImmutable('today')` (browser wall-clock), not the MockClock.
 */
final class AdminOnboardingFormPaymentUxTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testInitialRenderShowsMonthlyOnlyWithDiscoverabilityHint(): void
    {
        // No method and no dates chosen yet: the initial static choices must be
        // consistent with the no-dates rule — MONTHLY only, plus the hint that
        // more options unlock via the rental length.
        $component = $this->makeComponentWithPlaceAndType();

        $html = $component->render()->toString();
        self::assertStringContainsString('Frekvence plateb', $html);
        self::assertStringContainsString('Měsíční platba', $html);
        self::assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);
        self::assertStringContainsString('Další možnosti frekvence platby se zobrazí podle zvolené délky pronájmu.', $html);

        // Method card must come first: choose the method, then the frequency adapts.
        $methodPos = strpos($html, 'Platební metoda');
        $frequencyPos = strpos($html, 'Frekvence plateb');
        self::assertNotFalse($methodPos);
        self::assertNotFalse($frequencyPos);
        self::assertLessThan($frequencyPos, $methodPos);
    }

    public function testGopayHidesFrequencyCardEntirely(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload()]);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('Frekvence plateb', $html);
        self::assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);
        // The card-means-automatic-monthly note moved into the method card.
        self::assertStringContainsString('Frekvence platby je vždy automatická měsíční', $html);
    }

    public function testBankTransferMidWindowOffersUpfrontButNotYearly(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'paymentMethod' => 'bank_transfer',
        ])]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Frekvence plateb', $html);
        self::assertStringContainsString('Jednorázová platba předem (celá částka)', $html);
        self::assertStringContainsString('Celý pronájem předem jedním bankovním převodem.', $html);
        self::assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringNotContainsString('první platba pokryje prvních 12 měsíců', $html);
        self::assertStringNotContainsString('Další možnosti frekvence platby se zobrazí', $html);
    }

    public function testBankTransferLongWindowOffersYearlyAndTranchedUpfrontDescription(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'paymentMethod' => 'bank_transfer',
            'endDate' => (new \DateTimeImmutable('+390 days'))->format('Y-m-d'),
        ])]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringContainsString('Jedna platba předem na každý rok pronájmu se slevou 10 %', $html);
        self::assertStringContainsString('Jednorázová platba předem (celá částka)', $html);
        // > 12 months: the upfront description must reflect the yearly tranches (bff888e).
        self::assertStringContainsString('první platba pokryje prvních 12 měsíců', $html);
    }

    public function testExternalNeverOffersUpfrontOption(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'paymentMethod' => 'external',
            'isExternallyPrepaid' => '1',
            'paidThroughDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
        ])]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Frekvence plateb', $html);
        self::assertStringContainsString('Měsíční platba', $html);
        // EXTERNAL + upfront is a validator violation — the radio must not even render.
        self::assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);

        // Yearly stays available for EXTERNAL on long windows ("převodem nebo externě").
        $component->submitForm(['admin_onboarding_form' => [
            'endDate' => (new \DateTimeImmutable('+390 days'))->format('Y-m-d'),
        ]]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);
    }

    public function testGopayAfterStaleUpfrontSelectionSubmitsAsMonthly(): void
    {
        // Sequence from the requirement: bank transfer → one_time → back to GoPay.
        // The frequency card is hidden for GoPay, so the stale 'one_time' value on
        // the wire must be normalized to MONTHLY instead of tripping the
        // GOPAY+ONE_TIME violation on a field the admin cannot see.
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'email' => 'ux.stale-gopay@example.com',
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'one_time',
        ])]);
        $component->submitForm(['admin_onboarding_form' => ['paymentMethod' => 'gopay']]);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('Jednorázovou platbu celé částky lze provést pouze bankovním převodem.', $html);
        self::assertStringNotContainsString('Frekvence plateb', $html);

        $component->call('selectStorage', ['storageId' => $this->storageA2()->id->toRfc4122()]);
        $component->call('submit');

        $response = $component->response();
        self::assertTrue($response->isRedirect(), 'GoPay submit with a stale one_time frequency must pass, got: '.$response->getStatusCode());

        $order = $this->findOrderByCustomerEmail('ux.stale-gopay@example.com');
        self::assertSame(PaymentFrequency::MONTHLY, $order->paymentFrequency);
        self::assertSame(BillingMode::AUTO_RECURRING, $order->billingMode);
    }

    public function testSurchargeNoticeShownForBankTransferWhenConfigured(): void
    {
        $settings = static::getContainer()->get(PlatformSettingsRepository::class)->getSettings();
        $settings->updateSurcharge(10_000, static::getContainer()->get(ClockInterface::class)->now());
        $this->entityManager->flush();

        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'paymentMethod' => 'bank_transfer',
        ])]);

        $html = $component->render()->toString();
        self::assertStringContainsString('je účtován příplatek 100 Kč za každé fakturační období', $html);
    }

    public function testSurchargeNoticeHiddenWhenSurchargeIsZero(): void
    {
        $settings = static::getContainer()->get(PlatformSettingsRepository::class)->getSettings();
        $settings->updateSurcharge(0, static::getContainer()->get(ClockInterface::class)->now());
        $this->entityManager->flush();

        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'paymentMethod' => 'bank_transfer',
        ])]);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('příplatek', $html);
    }

    /**
     * Browser wire format: every value a string, exactly as Live sends formValues.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'email' => 'ux.onboarding@example.com',
            'firstName' => 'Jan',
            'lastName' => 'Novák',
            'phone' => '',
            'birthDate' => '1990-01-01',
            'companyName' => '',
            'companyId' => '',
            'companyVatId' => '',
            'billingStreet' => 'Hlavní 1',
            'billingCity' => 'Praha',
            'billingPostalCode' => '110 00',
            // Skip the Photon registry lookup (network) — override asserts the address.
            'addressOverride' => '1',
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            // Card payments (GoPay) require a rental of at least 31 days — keep
            // the default window comfortably above that threshold.
            'endDate' => (new \DateTimeImmutable('+55 days'))->format('Y-m-d'),
            'paymentMethod' => 'gopay',
            'paymentFrequency' => 'monthly',
            'monthlyPriceMode' => 'standard',
            'customMonthlyPriceInCzk' => '',
            'paidThroughDate' => '',
            'variableSymbol' => '',
            'debtAmountInCzk' => '',
        ], $overrides);
    }

    private function makeComponentWithPlaceAndType(): TestLiveComponent
    {
        $place = $this->entityManager->getRepository(Place::class)
            ->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place);

        $storageType = $this->entityManager->getRepository(StorageType::class)
            ->findOneBy(['name' => 'Maly box', 'place' => $place]);
        \assert($storageType instanceof StorageType);

        /** @var KernelBrowser $client */
        $client = static::getContainer()->get('test.client');

        $component = $this->createLiveComponent('AdminOnboardingForm', [
            'placeId' => $place->id->toRfc4122(),
            'storageTypeId' => $storageType->id->toRfc4122(),
        ], $client)->actingAs($this->admin());

        // An invalid live form throws UnprocessableEntityHttpException, which the
        // LiveComponentSubscriber turns into the production 422 re-render — but only
        // when the kernel catches exceptions. TestLiveComponent switches catching
        // off in its constructor; switch it back on to test what the browser sees.
        $client->catchExceptions(true);

        return $component;
    }

    private function admin(): User
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($admin instanceof User);

        return $admin;
    }

    private function storageA2(): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy(['number' => 'A2']);
        \assert($storage instanceof Storage);

        return $storage;
    }

    private function findOrderByCustomerEmail(string $email): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.user', 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order, sprintf('Expected an order for customer "%s".', $email));

        return $order;
    }
}
