<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Pins the null/zero distinction in templates/admin/order/_onboarding_banner.html.twig.
 *
 * The bug previously used Twig loose `==`: `order.individualMonthlyAmount == 0`
 * which evaluates true for null too, so admin-created orders without an
 * individual price (null) wrongly rendered "Smlouva zdarma".
 *
 * The fix uses `is same as(0)` (strict identity) — null is not 0.
 */
final class OnboardingBannerRenderingTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->twig = $container->get('test.twig');
    }

    public function testFreeContractRendersZdarmaBanner(): void
    {
        $order = $this->createOrder(individualMonthlyAmount: 0);

        $rendered = $this->renderBanner($order);

        self::assertStringContainsString('Smlouva zdarma', $rendered);
    }

    public function testStandardPricingDoesNotRenderZdarmaBanner(): void
    {
        // individualMonthlyAmount = null → standard storage rate. With the loose
        // `==` comparison, Twig would treat null == 0 as true and incorrectly
        // surface "Smlouva zdarma".
        $order = $this->createOrder(individualMonthlyAmount: null);
        $order->markAsAdminCreated();

        $rendered = $this->renderBanner($order);

        self::assertStringNotContainsString('Smlouva zdarma', $rendered);
    }

    public function testIndividualPriceDoesNotRenderZdarmaBanner(): void
    {
        $order = $this->createOrder(individualMonthlyAmount: 15_000);

        $rendered = $this->renderBanner($order);

        self::assertStringNotContainsString('Smlouva zdarma', $rendered);
        self::assertStringContainsString('Individuální měsíční cena', $rendered);
    }

    private function renderBanner(Order $order): string
    {
        return $this->twig->render('admin/order/_onboarding_banner.html.twig', ['order' => $order]);
    }

    private function createOrder(?int $individualMonthlyAmount): Order
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $user = new User(Uuid::v7(), 'tenant@example.com', 'password', 'Pavel', 'Nájemník', $now);
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Petr', 'Pronajímatel', $now);

        $place = new Place(
            id: Uuid::v7(),
            name: 'Sklad Praha',
            address: 'Testovací 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Malý box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10_000,
            defaultPricePerMonth: 35_000,
            defaultPricePerMonthLongTerm: 35_000,
            defaultPricePerYear: 35_000 * 12,
            createdAt: $now,
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
            owner: $owner,
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('+1 day'),
            endDate: $now->modify('+1 day')->modify('+12 months'),
            firstPaymentPrice: 35_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );

        if (null !== $individualMonthlyAmount) {
            $order->setOnboardingBillingTerms($individualMonthlyAmount, null);
        }

        return $order;
    }
}
