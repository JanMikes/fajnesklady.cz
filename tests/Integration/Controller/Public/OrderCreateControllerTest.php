<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Form\OrderFormData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * The order page is public and now renders the storage map from inside the
 * OrderForm Live Component (spec 071). These guard that it loads for anonymous
 * and authenticated visitors and rejects malformed / mismatched routes.
 */
final class OrderCreateControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAnonymousCanLoadOrderPage(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Vytvořit objednávku', (string) $this->client->getResponse()->getContent());
        // The map is owned by the component now — its controller mount must be present.
        self::assertSelectorExists('[data-controller~="storage-map"]');

        // Spec 079: the payment-deadline notice renders the place's configured
        // window (fixture default 3), not the pre-017 hardcoded "7 dní".
        self::assertStringContainsString('3 dny', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('7 dní na dokončení platby', (string) $this->client->getResponse()->getContent());
    }

    public function testAuthenticatedUserCanLoadOrderPage(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');
        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
    }

    public function testMalformedPlaceIdReturns404(): void
    {
        [, $storageType, $a1] = $this->centrumSmall('A1');

        $this->client->request('GET', sprintf(
            '/objednavka/not-a-uuid/%s/%s',
            $storageType->id->toRfc4122(),
            $a1->id->toRfc4122(),
        ));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownPlaceReturns404(): void
    {
        [, $storageType, $a1] = $this->centrumSmall('A1');

        $this->client->request('GET', sprintf(
            '/objednavka/%s/%s/%s',
            '00000000-0000-7000-8000-000000000000',
            $storageType->id->toRfc4122(),
            $a1->id->toRfc4122(),
        ));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testStorageOfDifferentTypeReturns400(): void
    {
        [$place, $smallType] = $this->centrumSmall('A1');
        // B1 is a Medium box at the same place — it does not belong to the Small type.
        $b1 = $this->findStorageByNumber('B1');

        $this->client->request('GET', $this->orderUrl($place, $smallType, $b1));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminOnlyStorageTypeReturns404(): void
    {
        // AO1 is a storage of the admin-only type at Praha Centrum — never publicly orderable.
        $ao1 = $this->findStorageByNumber('AO1');

        $this->client->request('GET', $this->orderUrl($ao1->place, $ao1->storageType, $ao1));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingStorageRedirectsToFirstAvailable(): void
    {
        [$place, $storageType] = $this->centrumSmall('A1');

        $this->client->request('GET', sprintf(
            '/objednavka/%s/%s',
            $place->id->toRfc4122(),
            $storageType->id->toRfc4122(),
        ));

        $this->assertResponseRedirects();
    }

    /**
     * Frequency card matrix for bank transfer (spec 078 + payment-UX pass):
     * the card always renders for bank transfer, method card first. Options:
     * only Měsíčně (+ discoverability hint) < 31 dní, Měsíčně + Jednorázově at
     * 31–359 dní, all three (incl. Ročně −10 %) at ≥ 360 dní. For GoPay the
     * card is hidden entirely (card = always automatic monthly, spec 076).
     * Rendered by the OrderForm Live Component from the session-hydrated
     * dates (the PRE_SET_DATA dynamic choices in OrderFormType).
     */
    public function testFrequencySectionShowsMonthlyOnlyWithHintForShortRental(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');
        $this->seedOrderFormSession(20);

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Frekvence platby', $html);
        $this->assertStringContainsString('Měsíční platba', $html);
        $this->assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);
        $this->assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        $this->assertStringContainsString('Další možnosti frekvence platby se zobrazí podle zvolené délky pronájmu.', $html);
    }

    public function testFrequencySectionHiddenEntirelyForGopay(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');
        $this->seedOrderFormSession(400, PaymentMethod::GOPAY);

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        // Card = always automatic monthly recurring — nothing to choose, even at 400 days.
        $this->assertStringNotContainsString('Frekvence platby', $html);
        $this->assertStringNotContainsString('Jednorázová platba předem (celá částka)', $html);
        $this->assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        $this->assertStringContainsString('Platba kartou probíhá automaticky jednou měsíčně', $html);
    }

    public function testFrequencySectionShowsMonthlyAndUpfrontAtFortyFiveDays(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');
        $this->seedOrderFormSession(45);

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Frekvence platby', $html);
        $this->assertStringContainsString('Jednorázová platba předem (celá částka)', $html);
        $this->assertStringNotContainsString('Roční platba (jednou ročně)', $html);
        // ≤ 12 months: single-transfer description, no tranche wording, no discoverability hint.
        $this->assertStringContainsString('Celý pronájem předem jedním bankovním převodem.', $html);
        $this->assertStringNotContainsString('první platba pokryje prvních 12 měsíců', $html);
        $this->assertStringNotContainsString('Další možnosti frekvence platby se zobrazí', $html);

        // Payment method card must come BEFORE the frequency card.
        $methodPos = strpos($html, 'Způsob platby');
        $frequencyPos = strpos($html, 'Frekvence platby');
        self::assertNotFalse($methodPos);
        self::assertNotFalse($frequencyPos);
        self::assertLessThan($frequencyPos, $methodPos, 'The "Způsob platby" card must render before "Frekvence platby".');
    }

    public function testFrequencySectionShowsAllThreeOptionsAtFourHundredDays(): void
    {
        [$place, $storageType, $a1] = $this->centrumSmall('A1');
        $this->seedOrderFormSession(400);

        $this->client->request('GET', $this->orderUrl($place, $storageType, $a1));

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Frekvence platby', $html);
        $this->assertStringContainsString('Měsíční platba', $html);
        $this->assertStringContainsString('Jednorázová platba předem (celá částka)', $html);
        $this->assertStringContainsString('Roční platba (jednou ročně)', $html);
        // > 12 months: the upfront option's description must reflect the yearly tranches (bff888e).
        $this->assertStringContainsString('první platba pokryje prvních 12 měsíců', $html);
    }

    private function seedOrderFormSession(int $rentalDays, PaymentMethod $paymentMethod = PaymentMethod::BANK_TRANSFER): void
    {
        $formData = new OrderFormData();
        $formData->startDate = new \DateTimeImmutable('2025-07-15');
        $formData->endDate = $formData->startDate->modify(sprintf('+%d days', $rentalDays));
        $formData->paymentMethod = $paymentMethod;

        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('order_form_data', $formData->toSessionArray());
        $session->save();

        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function orderUrl(Place $place, StorageType $storageType, Storage $storage): string
    {
        return sprintf(
            '/objednavka/%s/%s/%s',
            $place->id->toRfc4122(),
            $storageType->id->toRfc4122(),
            $storage->id->toRfc4122(),
        );
    }

    /**
     * @return array{Place, StorageType, Storage}
     */
    private function centrumSmall(string $storageNumber): array
    {
        $place = $this->entityManager->getRepository(Place::class)
            ->findOneBy(['name' => 'Sklad Praha - Centrum']);
        \assert($place instanceof Place);

        $storageType = $this->entityManager->getRepository(StorageType::class)
            ->findOneBy(['name' => 'Maly box', 'place' => $place]);
        \assert($storageType instanceof StorageType);

        return [$place, $storageType, $this->findStorageByNumber($storageNumber)];
    }

    private function findStorageByNumber(string $number): Storage
    {
        $storage = $this->entityManager->getRepository(Storage::class)
            ->findOneBy(['number' => $number]);
        \assert($storage instanceof Storage);

        return $storage;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
