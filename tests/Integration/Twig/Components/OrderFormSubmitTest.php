<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Silent-422 regression for the PUBLIC order form (parity with
 * {@see AdminOnboardingFormSubmitTest::testEmptyAddressRevealsAddressFieldsWithErrors}):
 * the three billing fields live inside a hidden container behind the address
 * search box, so their violations after a submit must reveal the container —
 * otherwise the customer's submit fails with no visible feedback.
 */
final class OrderFormSubmitTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testEmptyAddressSubmitRevealsAddressFieldsWithErrors(): void
    {
        $component = $this->makeComponent();
        $component->submitForm(['order_form' => [
            'firstName' => 'Jan',
            'lastName' => 'Novák',
            'email' => 'zakaznik.order@example.com',
            'billingStreet' => '',
            'billingCity' => '',
            'billingPostalCode' => '',
        ]], 'submit');

        $crawler = $component->render()->crawler();

        $manual = $crawler->filter('[data-address-autocomplete-target="manualSection"]')->first();
        self::assertGreaterThan(0, $manual->count());
        self::assertStringNotContainsString(
            'hidden',
            (string) $manual->attr('class'),
            'Address violations must reveal the manual address fields on the order form.',
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
        self::assertStringContainsString('Vyplňte prosím fakturační adresu', $html);
    }

    public function testAddressSearchLabelIsMarkedRequired(): void
    {
        // The address is mandatory for every order (OrderFormData::validateAddress),
        // so the "Adresa" search label must carry the required marker — the macro
        // derives it from the underlying billingStreet field's `required` flag.
        $component = $this->makeComponent();

        $crawler = $component->render()->crawler();
        $label = $crawler->filter('[data-address-autocomplete-target="searchSection"] label')->first();

        self::assertGreaterThan(0, $label->count());
        self::assertStringContainsString('form-label-required', (string) $label->attr('class'));
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
