<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Order;
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
 * End-to-end submits of the admin onboarding Live Component — the exact wire
 * format the browser sends (formValues strings + live actions), through real
 * form binding, validation, storage selection, and the command handler.
 *
 * Covers the reported production bugs:
 *  - admins "unable to submit" with date errors (backdated windows,
 *    paidThroughDate = today must be accepted end-to-end);
 *  - a 422 whose only violations sat on the hidden billing-address fields, so
 *    the submit failed with no visible feedback (spec: silent-422 regression).
 *
 * Dates are relative to REAL today: the FormData callbacks compare against
 * `new \DateTimeImmutable('today')` (browser wall-clock), not the MockClock.
 */
final class AdminOnboardingFormSubmitTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testFixedTermHappyPathCreatesOrderWithExactDates(): void
    {
        $start = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('+55 days'))->format('Y-m-d');

        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'email' => 'e2e.limited@example.com',
            'startDate' => $start,
            'endDate' => $end,
        ])]);
        $component->call('selectStorage', ['storageId' => $this->storageA2()->id->toRfc4122()]);
        $component->call('submit');

        $response = $component->response();
        self::assertTrue($response->isRedirect(), 'Valid submit must redirect, got: '.$response->getStatusCode());
        self::assertSame('/portal/admin/orders', $response->headers->get('Location'));

        $order = $this->findOrderByCustomerEmail('e2e.limited@example.com');
        // The exact dates the admin picked must survive the whole pipeline.
        self::assertSame($start, $order->startDate->format('Y-m-d'));
        self::assertNotNull($order->endDate);
        self::assertSame($end, $order->endDate->format('Y-m-d'));
        self::assertNotNull($order->signingToken, 'Onboarding order must carry a signing token for the customer link.');
        self::assertSame('A2', $order->storage?->number);
    }

    public function testBackdatedStartWithPaidThroughTodaySubmits(): void
    {
        // Regression: a backdated rental prepaid through *today* was reported as
        // rejected ("today is in the past"). It must submit and persist the date.
        $start = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('+40 days'))->format('Y-m-d');
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'email' => 'e2e.backdated@example.com',
            'startDate' => $start,
            'endDate' => $end,
            'paymentMethod' => 'external',
            'isExternallyPrepaid' => '1',
            'paidThroughDate' => $today,
        ])]);
        $component->call('selectStorage', ['storageId' => $this->storageA2()->id->toRfc4122()]);
        $component->call('submit');

        self::assertTrue($component->response()->isRedirect(), 'Backdated + paid-through-today must submit.');

        $order = $this->findOrderByCustomerEmail('e2e.backdated@example.com');
        self::assertSame($start, $order->startDate->format('Y-m-d'));
        self::assertNotNull($order->paidThroughDate);
        self::assertSame($today, $order->paidThroughDate->format('Y-m-d'));
    }

    public function testMissingEndDateShowsError(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'endDate' => '',
        ])], 'submit');

        $html = $component->render()->toString();
        self::assertStringContainsString('Zadejte datum konce.', $html);
    }

    public function testRentalShorterThanSevenDaysShowsError(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+13 days'))->format('Y-m-d'),
        ])], 'submit');

        $html = $component->render()->toString();
        self::assertStringContainsString('Minimální doba pronájmu je 7 dní.', $html);
    }

    public function testUnderageBirthDateShowsError(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'birthDate' => (new \DateTimeImmutable('-17 years'))->format('Y-m-d'),
        ])], 'submit');

        $html = $component->render()->toString();
        self::assertStringContainsString('Nájemce musí být starší 18 let.', $html);
    }

    public function testPaidThroughDateBeforeStartShowsError(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'paymentMethod' => 'external',
            'isExternallyPrepaid' => '1',
            'paidThroughDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            // start is in the future, so paid-through "today" precedes it
            'startDate' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d'),
            'endDate' => (new \DateTimeImmutable('+40 days'))->format('Y-m-d'),
        ])], 'submit');

        $html = $component->render()->toString();
        self::assertStringContainsString('Datum předplatby nemůže být před datem začátku.', $html);
    }

    public function testEmptyAddressRevealsAddressFieldsWithErrors(): void
    {
        // Silent-422 regression: the three billing fields live inside a hidden
        // container behind the address search box. When their NotBlank violations
        // are the outcome of a submit, the server render must reveal the container
        // (and flag the Stimulus controller), or the submit fails with no visible
        // feedback anywhere.
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload([
            'billingStreet' => '',
            'billingCity' => '',
            'billingPostalCode' => '',
        ])], 'submit');

        $crawler = $component->render()->crawler();

        $manual = $crawler->filter('[data-address-autocomplete-target="manualSection"]')->first();
        self::assertGreaterThan(0, $manual->count());
        self::assertStringNotContainsString(
            'hidden',
            (string) $manual->attr('class'),
            'Address violations must reveal the manual address fields.',
        );

        $controllerRoot = $crawler->filter('[data-controller~="address-autocomplete"]')->first();
        self::assertSame(
            'true',
            $controllerRoot->attr('data-address-autocomplete-has-violation-value'),
            'The Stimulus controller must be told about the violation, or its next morph re-hides the fields.',
        );

        $html = $component->render()->toString();
        self::assertStringContainsString('Zadejte ulici.', $html);
        self::assertStringContainsString('Zadejte město.', $html);
        self::assertStringContainsString('Zadejte PSČ.', $html);
        // Fallback hint inside the (now hidden) search section, for the case where
        // the user explicitly switched back to the search box before submitting.
        self::assertStringContainsString('Vyplňte prosím fakturační adresu', $html);
    }

    public function testValidFormWithoutStorageSelectionShowsStorageError(): void
    {
        $component = $this->makeComponentWithPlaceAndType();
        $component->submitForm(['admin_onboarding_form' => $this->payload()], 'submit');

        $html = $component->render()->toString();
        self::assertStringContainsString('Vyberte skladovou jednotku z mapy.', $html);
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
            'email' => 'e2e.zakaznik@example.com',
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
