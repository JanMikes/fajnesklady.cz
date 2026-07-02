<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Repository\StorageTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class StorageTypeReorderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private StorageTypeRepository $storageTypeRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->storageTypeRepository = static::getContainer()->get(StorageTypeRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testAdminCanReorderStorageTypes(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $originalNames = array_map(
            static fn (StorageType $storageType): string => $storageType->name,
            $this->storageTypeRepository->findByPlace($place),
        );
        $reversedIds = array_reverse(array_map(
            static fn (StorageType $storageType): string => $storageType->id->toRfc4122(),
            $this->storageTypeRepository->findByPlace($place),
        ));

        $this->postReorder($place, $reversedIds);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $place = $this->getPlace('Sklad Praha - Centrum');
        $reorderedNames = array_map(
            static fn (StorageType $storageType): string => $storageType->name,
            $this->storageTypeRepository->findByPlace($place),
        );

        $this->assertSame(array_reverse($originalNames), $reorderedNames);
    }

    public function testTypesMissingFromSubmittedListKeepRelativeOrderAfterListedOnes(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $storageTypes = $this->storageTypeRepository->findByPlace($place);
        $last = end($storageTypes);
        \assert($last instanceof StorageType);
        $restNames = array_map(
            static fn (StorageType $storageType): string => $storageType->name,
            array_slice($storageTypes, 0, -1),
        );

        // Submit only the last type — it moves to the front, the rest keep order
        $this->postReorder($place, [$last->id->toRfc4122()]);

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $place = $this->getPlace('Sklad Praha - Centrum');
        $reorderedNames = array_map(
            static fn (StorageType $storageType): string => $storageType->name,
            $this->storageTypeRepository->findByPlace($place),
        );

        $this->assertSame([$last->name, ...$restNames], $reorderedNames);
    }

    public function testListPageExposesSortableWiring(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->client->request('GET', sprintf('/portal/places/%s/storage-types', $place->id->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('data-controller="sortable-list"', $body);
        $this->assertStringContainsString($this->urlFor($place), $body);
        $this->assertStringContainsString('data-sortable-id', $body);
        $this->assertStringContainsString('data-sortable-handle', $body);
    }

    public function testUnauthenticatedIsRedirectedToLogin(): void
    {
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->postReorder($place, [Uuid::v7()->toRfc4122()]);

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/login', $location);
    }

    public function testTenantIsRejected(): void
    {
        $this->loginAs('tenant@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->postReorder($place, [Uuid::v7()->toRfc4122()]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLandlordIsRejected(): void
    {
        // Reordering is admin-only, even for the landlord owning the place
        $this->loginAs('landlord@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->postReorder($place, [Uuid::v7()->toRfc4122()]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testStorageTypeFromAnotherPlaceIsRejected(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');
        $foreignPlace = $this->getPlace('Sklad Brno');
        $foreignTypes = $this->storageTypeRepository->findByPlace($foreignPlace);
        $foreignType = reset($foreignTypes);
        \assert($foreignType instanceof StorageType);

        $this->postReorder($place, [$foreignType->id->toRfc4122()]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownStorageTypeIdIsRejected(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->postReorder($place, [Uuid::v7()->toRfc4122()]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMissingIdsAreRejected(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->client->request(
            'POST',
            $this->urlFor($place),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $this->assertResponseStatusCodeSame(400);
    }

    public function testInvalidIdIsRejected(): void
    {
        $this->loginAs('admin@example.com');
        $place = $this->getPlace('Sklad Praha - Centrum');

        $this->postReorder($place, ['not-a-uuid']);

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @param list<string> $ids
     */
    private function postReorder(Place $place, array $ids): void
    {
        $this->client->request(
            'POST',
            $this->urlFor($place),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => $ids], JSON_THROW_ON_ERROR),
        );
    }

    private function urlFor(Place $place): string
    {
        return sprintf('/portal/places/%s/storage-types/reorder', $place->id->toRfc4122());
    }

    private function loginAs(string $email): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User);
        $this->client->loginUser($user, 'main');
    }

    private function getPlace(string $placeName): Place
    {
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => $placeName]);
        \assert($place instanceof Place);

        return $place;
    }
}
