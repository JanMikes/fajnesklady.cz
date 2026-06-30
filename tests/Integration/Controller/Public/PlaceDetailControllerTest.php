<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Public;

use App\Entity\Place;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class PlaceDetailControllerTest extends WebTestCase
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

    public function testPageLoadsForActivePlace(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($place->name, (string) $this->client->getResponse()->getContent());
    }

    /**
     * Bug regression: the place detail used the gallery "hero-only" layout, which
     * rendered only the first photo as a visible <img> and hid the remaining photos
     * as empty <a class="glightbox hidden"> anchors (no <img>). Customers therefore
     * saw a single photo. The fix switches to the "hero" layout so every photo is a
     * visible thumbnail. MEDIUM_CENTRUM has three photos; box-gray-portrait.jpg is
     * its second photo and is unique to that type, so a visible <img> referencing it
     * proves more than the hero is rendered.
     */
    public function testRendersMoreThanOnePhotoPerStorageType(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        // Hero (first photo) is visible.
        $this->assertMatchesRegularExpression('~<img[^>]*box-orange-wide\.jpg~', $body);
        // A non-hero photo is now a visible thumbnail <img>, not just a hidden lightbox anchor.
        $this->assertMatchesRegularExpression(
            '~<img[^>]*box-gray-portrait\.jpg~',
            $body,
            'Second storage-type photo must render as a visible thumbnail, not only behind the lightbox.',
        );
    }

    public function testAdminOnlyStorageTypeIsHidden(): void
    {
        $place = $this->findPlaceByName('Sklad Praha - Centrum');

        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // The admin-only fixture type at this place must not be offered to the public…
        $this->assertStringNotContainsString('Admin box', $body);
        // …while normal active types still render.
        $this->assertStringContainsString('Maly box', $body);
    }

    public function testAuthenticatedUserIsRedirectedToBrowseDetail(): void
    {
        $user = $this->findUserByEmail('user@example.com');
        $this->client->loginUser($user, 'main');

        $place = $this->findPlaceByName('Sklad Praha - Centrum');
        $this->client->request('GET', '/pobocka/'.$place->id->toRfc4122());

        $this->assertResponseRedirects('/portal/pobocka/'.$place->id->toRfc4122());
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $this->client->request('GET', '/pobocka/'.Uuid::v7()->toRfc4122());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturns404ForMalformedId(): void
    {
        $this->client->request('GET', '/pobocka/not-a-uuid');

        $this->assertResponseStatusCodeSame(404);
    }

    private function findPlaceByName(string $name): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $name]);
        \assert($place instanceof Place, sprintf('Fixture place "%s" not found', $name));

        return $place;
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User with email "%s" not found in fixtures', $email));

        return $user;
    }
}
