<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Repository\PlatformSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Payment-UX pass on the public order form: the "Způsob platby" card renders
 * BEFORE "Frekvence platby", GoPay hides the frequency card entirely (card =
 * always automatic monthly recurring, spec 076), bank transfer reveals it with
 * per-option descriptions, and a GoPay submit carrying a stale 'one_time' /
 * 'yearly' frequency (picked earlier under bank transfer, now hidden) is
 * normalized to MONTHLY server-side instead of tripping a violation the
 * customer cannot see.
 *
 * Dates are relative to REAL today: OrderFormData callbacks compare against
 * `new \DateTimeImmutable('today')` (browser wall-clock), not the MockClock.
 */
final class OrderFormPaymentUxTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testGopayDefaultHidesFrequencyCardEntirely(): void
    {
        $component = $this->makeComponent();

        $html = $component->render()->toString();
        self::assertStringContainsString('Způsob platby', $html);
        self::assertStringNotContainsString('Frekvence platby', $html);
        self::assertStringContainsString('Platba kartou probíhá automaticky jednou měsíčně', $html);
    }

    public function testBankTransferWithoutDatesShowsMonthlyOnlyWithDiscoverabilityHint(): void
    {
        $component = $this->makeComponent();
        $component->submitForm(['order_form' => ['paymentMethod' => 'bank_transfer']]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Frekvence platby', $html);
        self::assertStringContainsString('Měsíční platba', $html);
        self::assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);
        self::assertStringContainsString('Další možnosti frekvence platby se zobrazí podle zvolené délky pronájmu.', $html);

        // Method card must come first: choose the method, then the frequency adapts.
        $methodPos = strpos($html, 'Způsob platby');
        $frequencyPos = strpos($html, 'Frekvence platby');
        self::assertNotFalse($methodPos);
        self::assertNotFalse($frequencyPos);
        self::assertLessThan($frequencyPos, $methodPos);
    }

    public function testBankTransferMidWindowOffersUpfrontWithSingleTransferDescription(): void
    {
        $component = $this->makeComponent();
        $component->submitForm(['order_form' => [
            'paymentMethod' => 'bank_transfer',
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+55 days'))->format('Y-m-d'),
        ]]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Jednorázová platba předem (celá částka)', $html);
        self::assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringContainsString('Celý pronájem předem jedním bankovním převodem.', $html);
        self::assertStringNotContainsString('první platba pokryje prvních 12 měsíců', $html);
        self::assertStringNotContainsString('Další možnosti frekvence platby se zobrazí', $html);
    }

    public function testBankTransferLongWindowOffersYearlyAndTranchedUpfrontDescription(): void
    {
        $component = $this->makeComponent();
        $component->submitForm(['order_form' => [
            'paymentMethod' => 'bank_transfer',
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+390 days'))->format('Y-m-d'),
        ]]);

        $html = $component->render()->toString();
        self::assertStringContainsString('Roční platba (jednou ročně)', $html);
        self::assertStringContainsString('Jedna platba předem na každý rok pronájmu se slevou 10 %.', $html);
        self::assertStringContainsString('Jednorázová platba předem (celá částka)', $html);
        // > 12 months: the upfront description must reflect the yearly tranches (bff888e).
        self::assertStringContainsString('první platba pokryje prvních 12 měsíců', $html);
    }

    public function testGopayAfterStaleUpfrontFrequencyNormalizesToMonthlyAndSubmits(): void
    {
        // Sequence from the requirement: bank transfer → one_time → back to GoPay.
        // The hidden frequency field still carries 'one_time' on the wire — the
        // submit must NOT trip the GOPAY+ONE_TIME violation.
        $component = $this->makeComponent();
        $component->submitForm(['order_form' => $this->payload([
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'one_time',
        ])]);
        $component->submitForm(['order_form' => ['paymentMethod' => 'gopay']]);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('Jednorázovou platbu celé částky lze provést pouze bankovním převodem.', $html);
        self::assertStringNotContainsString('Frekvence platby', $html);
        // The bound schedule preview proves the value was normalized to monthly recurring.
        self::assertStringContainsString('Měsíční platba', $html);

        $component->call('submit');
        self::assertTrue(
            $component->response()->isRedirect(),
            'GoPay submit with a stale one_time frequency must normalize to monthly and pass, got: '.$component->response()->getStatusCode(),
        );
    }

    public function testGopayAfterStaleYearlyFrequencyNormalizesToMonthlyAndSubmits(): void
    {
        // Same sequence with YEARLY — the browser-reachable variant (the GoPay
        // radio is never disabled while yearly is selected).
        $component = $this->makeComponent();
        $component->submitForm(['order_form' => $this->payload([
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'yearly',
            'endDate' => (new \DateTimeImmutable('+390 days'))->format('Y-m-d'),
        ])]);
        $component->submitForm(['order_form' => ['paymentMethod' => 'gopay']]);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('Roční platbu lze platit pouze bankovním převodem.', $html);
        self::assertStringNotContainsString('Frekvence platby', $html);

        $component->call('submit');
        self::assertTrue(
            $component->response()->isRedirect(),
            'GoPay submit with a stale yearly frequency must normalize to monthly and pass, got: '.$component->response()->getStatusCode(),
        );
    }

    public function testSurchargeNoticeShownForBankTransferWhenConfigured(): void
    {
        $settings = static::getContainer()->get(PlatformSettingsRepository::class)->getSettings();
        $settings->updateSurcharge(10_000, static::getContainer()->get(ClockInterface::class)->now());
        $this->entityManager->flush();

        $component = $this->makeComponent();
        $component->submitForm(['order_form' => ['paymentMethod' => 'bank_transfer']]);

        $html = $component->render()->toString();
        self::assertStringContainsString('je účtován příplatek 100 Kč za každé fakturační období', $html);
    }

    public function testSurchargeNoticeHiddenWhenSurchargeIsZero(): void
    {
        $settings = static::getContainer()->get(PlatformSettingsRepository::class)->getSettings();
        $settings->updateSurcharge(0, static::getContainer()->get(ClockInterface::class)->now());
        $this->entityManager->flush();

        $component = $this->makeComponent();
        $component->submitForm(['order_form' => ['paymentMethod' => 'bank_transfer']]);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('příplatek', $html);
        // The rest of the bank-transfer notice (no availability guarantee) stays.
        self::assertStringContainsString('Negarantujeme dostupnost vaší skladovací jednotky', $html);
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
            'email' => 'ux.zakaznik@example.com',
            'firstName' => 'Jan',
            'lastName' => 'Novák',
            'phone' => '+420 123 456 789',
            'birthDate' => '1990-01-01',
            'plainPassword' => '',
            'billingStreet' => 'Hlavní 1',
            'billingCity' => 'Praha',
            'billingPostalCode' => '110 00',
            // Skip the Photon registry lookup (network) — override asserts the address.
            'addressOverride' => '1',
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+55 days'))->format('Y-m-d'),
            'paymentMethod' => 'gopay',
            'paymentFrequency' => 'monthly',
        ], $overrides);
    }

    private function makeComponent(): TestLiveComponent
    {
        $place = $this->entityManager->getRepository(Place::class)
            ->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place);

        $storageType = $this->entityManager->getRepository(StorageType::class)
            ->findOneBy(['name' => 'Maly box', 'place' => $place]);
        \assert($storageType instanceof StorageType);

        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy(['number' => 'A2']);
        \assert($storage instanceof Storage);

        // OrderForm::instantiateForm() reads the session (order_form_data prefill).
        // TestLiveComponent dehydrates the component once OUTSIDE any request, so
        // give the RequestStack a request carrying a session for that first mount.
        $request = Request::create('/objednavka');
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = static::getContainer()->get('request_stack');
        $requestStack->push($request);

        /** @var KernelBrowser $client */
        $client = static::getContainer()->get('test.client');

        $component = $this->createLiveComponent('OrderForm', [
            'place' => $place,
            'storageType' => $storageType,
            'storageId' => $storage->id->toRfc4122(),
        ], $client);

        // Invalid live forms throw UnprocessableEntityHttpException; catching it lets
        // the LiveComponentSubscriber produce the production 422 re-render.
        $client->catchExceptions(true);

        return $component;
    }
}
