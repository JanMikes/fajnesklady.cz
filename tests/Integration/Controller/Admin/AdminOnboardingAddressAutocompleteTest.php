<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec 061: the admin onboarding form must reach parity with the order form by
 * rendering the shared `_address_override` macro — Photon autocomplete targets,
 * an override checkbox hidden until a server-side violation, and the native
 * `inputmode="numeric"` on PSČ.
 */
final class AdminOnboardingAddressAutocompleteTest extends WebTestCase
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

    public function testAddressFieldsCarryAutocompleteController(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseIsSuccessful();

        // The macro wraps the three address fields in the address-autocomplete controller.
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-controller~="address-autocomplete"]')->count(),
            'Address fields should be wrapped in the address-autocomplete Stimulus controller.',
        );

        // All three inputs expose the controller targets so click-to-fill works.
        self::assertGreaterThan(0, $crawler->filter('[data-address-autocomplete-target="streetInput"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-address-autocomplete-target="cityInput"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-address-autocomplete-target="postalCodeInput"]')->count());
    }

    public function testPostalCodeInputUsesNumericKeypadAndKeepsPlaceholder(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseIsSuccessful();

        $postal = $crawler->filter('[data-address-autocomplete-target="postalCodeInput"]')->first();

        self::assertSame('numeric', $postal->attr('inputmode'), 'PSČ must request the numeric keypad on mobile.');
        // Spec 062: browser address autofill is suppressed on the onboarding form so the
        // admin's own PSČ isn't injected; the numeric keypad hint above stays.
        self::assertSame('off', $postal->attr('autocomplete'), 'PSČ autofill must be suppressed on the onboarding form.');
        // type="number" would strip the "110 00" space — must stay text.
        self::assertNotSame('number', $postal->attr('type'));
        // The merge must not clobber the FormType placeholder / maxlength.
        self::assertSame('110 00', $postal->attr('placeholder'));
        self::assertSame('10', $postal->attr('maxlength'));
    }

    public function testStreetAndCityHaveAutofillSuppressed(): void
    {
        // Spec 062: the onboarding form passes browserAutofill=false to the address macro
        // so the browser does not inject the admin's own street/city; the Photon dropdown
        // targets (asserted above) keep working.
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseIsSuccessful();

        $street = $crawler->filter('[data-address-autocomplete-target="streetInput"]')->first();
        $city = $crawler->filter('[data-address-autocomplete-target="cityInput"]')->first();

        self::assertSame('off', $street->attr('autocomplete'));
        self::assertSame('off', $city->attr('autocomplete'));
    }

    public function testIdentityAndNumericFieldsSuppressAutofillAndKeepKeyboardHints(): void
    {
        // Spec 062: every identity/contact/numeric field on the onboarding form carries
        // autocomplete="off"; numeric fields still request the right mobile keypad.
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseIsSuccessful();

        $autocompleteOff = static function (string $nameSuffix) use ($crawler): void {
            $input = $crawler->filter(sprintf('[name$="[%s]"]', $nameSuffix))->first();
            self::assertGreaterThan(0, $input->count(), sprintf('Field "%s" should be rendered.', $nameSuffix));
            self::assertSame('off', $input->attr('autocomplete'), sprintf('Field "%s" must suppress browser autofill.', $nameSuffix));
        };

        // Identity / contact fields (always rendered).
        $autocompleteOff('email');
        $autocompleteOff('firstName');
        $autocompleteOff('lastName');
        $autocompleteOff('phone');
        $autocompleteOff('birthDate');

        // EmailType keeps its native type so the email keyboard + validation survive.
        self::assertSame('email', $crawler->filter('[name$="[email]"]')->first()->attr('type'));
        // TelType keeps the tel keypad.
        self::assertSame('tel', $crawler->filter('[name$="[phone]"]')->first()->attr('type'));
    }

    public function testOverrideCheckboxIsHiddenByDefault(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');

        $crawler = $this->client->request('GET', '/portal/admin/onboarding');

        $this->assertResponseIsSuccessful();

        $container = $crawler->filter('[data-address-autocomplete-target="overrideContainer"]')->first();

        self::assertGreaterThan(0, $container->count(), 'Override container must exist.');
        self::assertStringContainsString('hidden', (string) $container->attr('class'), 'Override must be hidden until a violation lands.');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
