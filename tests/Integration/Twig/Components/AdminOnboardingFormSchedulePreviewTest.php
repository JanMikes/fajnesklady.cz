<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The "Kalkulace plateb" card must mirror the contract the submit would
 * create for EVERY combination of pricing mode × payment frequency × payment
 * method × external prepayment (reported bug: an individual yearly price of
 * 12 000 Kč rendered the standard price-list calculation instead).
 *
 * Fixture rates for Maly box / Praha Centrum (storage A2): 150 Kč/week,
 * 500 Kč/month short-term, 430 Kč/month long-term, 4 300 Kč/year.
 *
 * Dates are relative to REAL today: the FormData validators compare against
 * `new \DateTimeImmutable('today')` (browser wall-clock), not the MockClock.
 */
final class AdminOnboardingFormSchedulePreviewTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testCardHiddenUntilStorageSelected(): void
    {
        $component = $this->makeComponent();
        $component->submitForm(['admin_onboarding_form' => $this->payload()]);

        self::assertStringNotContainsString('Kalkulace plateb', $component->render()->toString());
    }

    public function testCardRendersDirectlyUnderPricingModelCard(): void
    {
        $html = $this->renderWithStorage($this->payload());

        $pricingModel = strpos($html, 'Cenový model');
        $schedule = strpos($html, 'Kalkulace plateb');
        $externalPrepayment = strpos($html, 'Externí předplatné');

        self::assertNotFalse($pricingModel);
        self::assertNotFalse($schedule);
        self::assertNotFalse($externalPrepayment);
        self::assertLessThan($schedule, $pricingModel, 'Kalkulace must render below Cenový model.');
        self::assertLessThan($externalPrepayment, $schedule, 'Kalkulace must render above Externí předplatné.');
    }

    public function testStandardMonthlyShowsPriceListRate(): void
    {
        // 45 days → short-term tier 500 Kč/month.
        $html = $this->renderWithStorage($this->payload());

        self::assertStringContainsString('Měsíční platba: 500 Kč / měsíc', $html);
    }

    public function testCustomMonthlyPriceDrivesSchedule(): void
    {
        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'bank_transfer',
            'monthlyPriceMode' => 'custom',
            'customMonthlyPriceInCzk' => '1000',
        ]));

        self::assertStringContainsString('Měsíční platba: 1 000 Kč / měsíc', $html);
        self::assertStringNotContainsString('Měsíční platba: 500 Kč', $html);
        self::assertStringNotContainsString('vychází ze standardního ceníku', $html);
    }

    public function testCustomYearlyPriceDrivesSchedule(): void
    {
        // The reported bug: individual price + bank transfer + yearly payment,
        // 12 000 Kč / rok — the calculation showed the 4 300 Kč price-list rate.
        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'yearly',
            'monthlyPriceMode' => 'custom',
            'customMonthlyPriceInCzk' => '12000',
            'endDate' => (new \DateTimeImmutable('+10 days'))->modify('+24 months')->format('Y-m-d'),
        ]));

        self::assertStringContainsString('Roční platba: 12 000 Kč / rok', $html);
        // The price-list yearly rate must not drive the calculation (it stays
        // visible only in the selected-unit rate card).
        self::assertStringNotContainsString('Roční platba: 4 300 Kč', $html);
    }

    public function testCustomUpfrontTotalIsSinglePayment(): void
    {
        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'one_time',
            'monthlyPriceMode' => 'custom',
            'customMonthlyPriceInCzk' => '5000',
        ]));

        self::assertStringContainsString('Jednorázová platba předem: 5 000 Kč', $html);
    }

    public function testCustomUpfrontTotalUnavailableForTranchedRentalFallsBackToPriceList(): void
    {
        // > 12 monthly periods: the custom total is rejected by validation, so
        // the calculation must say so and show the price-list tranches
        // (12 × 430 Kč long-term = 5 160 Kč each).
        $start = new \DateTimeImmutable('+10 days');
        $end = $start;
        for ($i = 0; $i < 24; ++$i) {
            $end = $end->modify('+1 month');
        }

        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'one_time',
            'monthlyPriceMode' => 'custom',
            'customMonthlyPriceInCzk' => '9999',
            'endDate' => $end->format('Y-m-d'),
        ]));

        self::assertStringContainsString('Individuální celková cena je možná jen u jednorázové platby do 12 měsíců', $html);
        self::assertStringContainsString('první 5 160 Kč', $html);
        self::assertStringContainsString('odpovídá 430 Kč / měsíc', $html);
    }

    public function testCustomModeWithoutAmountFallsBackToPriceListWithHint(): void
    {
        $html = $this->renderWithStorage($this->payload([
            'monthlyPriceMode' => 'custom',
            'customMonthlyPriceInCzk' => '',
        ]));

        self::assertStringContainsString('Zadejte individuální cenu', $html);
        self::assertStringContainsString('Měsíční platba: 500 Kč / měsíc', $html);
    }

    public function testFreeModeShowsNoChargesNote(): void
    {
        $html = $this->renderWithStorage($this->payload([
            'monthlyPriceMode' => 'free',
        ]));

        self::assertStringContainsString('Smlouva je zdarma — zákazníkovi se nebude účtovat žádná platba.', $html);
        self::assertStringNotContainsString('Měsíční platba:', $html);
    }

    public function testGopayNormalisesStaleYearlyFrequencyToMonthly(): void
    {
        // Card = always automatic monthly (spec 076): a stale 'yearly' left over
        // from a bank-transfer selection must not leak into the calculation.
        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'gopay',
            'paymentFrequency' => 'yearly',
            'monthlyPriceMode' => 'custom',
            'customMonthlyPriceInCzk' => '1000',
            'endDate' => (new \DateTimeImmutable('+10 days'))->modify('+24 months')->format('Y-m-d'),
        ]));

        self::assertStringContainsString('Měsíční platba: 1 000 Kč / měsíc', $html);
        self::assertStringNotContainsString('Roční platba', $html);
    }

    public function testPartialExternalPrepaymentAnchorsScheduleAtPaidThroughDate(): void
    {
        $start = new \DateTimeImmutable('+10 days');
        $paidThrough = new \DateTimeImmutable('+40 days');

        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'bank_transfer',
            'isExternallyPrepaid' => '1',
            'paidThroughDate' => $paidThrough->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+100 days'))->format('Y-m-d'),
        ]));

        self::assertStringContainsString(
            sprintf('Zákazník má předplaceno do %s — kalkulace zobrazuje až platby od tohoto data.', $paidThrough->format('d.m.Y')),
            $html,
        );
        // First charge sits AT the paid-through date; nothing is listed for the
        // covered period from the start date.
        self::assertStringContainsString($paidThrough->format('d.m.Y'), $html);
        self::assertStringNotContainsString($start->format('d.m.Y'), $html);
    }

    public function testFullExternalPrepaymentShowsNoChargesNote(): void
    {
        $end = new \DateTimeImmutable('+100 days');

        $html = $this->renderWithStorage($this->payload([
            'paymentMethod' => 'bank_transfer',
            'isExternallyPrepaid' => '1',
            'paidThroughDate' => $end->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
        ]));

        self::assertStringContainsString(
            sprintf('Celý pronájem je předplacen externě do %s — nebude se účtovat žádná platba.', $end->format('d.m.Y')),
            $html,
        );
    }

    /**
     * @param array<string, string> $payload
     */
    private function renderWithStorage(array $payload): string
    {
        $component = $this->makeComponent();
        $component->submitForm(['admin_onboarding_form' => $payload]);
        $component->call('selectStorage', ['storageId' => $this->storageA2()->id->toRfc4122()]);

        return $component->render()->toString();
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
            'email' => 'preview.zakaznik@example.com',
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
            'addressOverride' => '1',
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            // 45 days → the short-term monthly tier (500 Kč) by default.
            'endDate' => (new \DateTimeImmutable('+55 days'))->format('Y-m-d'),
            'paymentMethod' => 'bank_transfer',
            'paymentFrequency' => 'monthly',
            'monthlyPriceMode' => 'standard',
            'customMonthlyPriceInCzk' => '',
            'paidThroughDate' => '',
            'variableSymbol' => '',
            'debtAmountInCzk' => '',
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

        /** @var KernelBrowser $client */
        $client = static::getContainer()->get('test.client');

        $component = $this->createLiveComponent('AdminOnboardingForm', [
            'placeId' => $place->id->toRfc4122(),
            'storageTypeId' => $storageType->id->toRfc4122(),
        ], $client)->actingAs($this->admin());

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
}
