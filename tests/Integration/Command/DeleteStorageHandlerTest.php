<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DeleteStorageCommand;
use App\Command\DeleteStorageHandler;
use App\DataFixtures\StorageFixtures;
use App\Entity\Storage;
use App\Exception\StorageCannotBeDeleted;
use App\Exception\StorageNotFound;
use App\Repository\StorageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DeleteStorageHandlerTest extends KernelTestCase
{
    private DeleteStorageHandler $handler;
    private StorageRepository $storageRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->handler = $container->get(DeleteStorageHandler::class);
        $this->storageRepository = $container->get(StorageRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testCanDeleteAvailableStorage(): void
    {
        /** @var Storage $storage */
        $storage = $this->getFixtureReference(StorageFixtures::REF_SMALL_A1);
        $storageId = $storage->id;

        $command = new DeleteStorageCommand(storageId: $storageId);
        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->expectException(StorageNotFound::class);
        $this->storageRepository->get($storageId);
    }

    public function testCannotDeleteOccupiedStorage(): void
    {
        // Storage B3 is OCCUPIED (has active contract from ContractFixtures)
        /** @var Storage $storage */
        $storage = $this->getFixtureReference(StorageFixtures::REF_MEDIUM_B3);

        $this->assertTrue($storage->isOccupied(), 'Storage B3 should be occupied');

        $command = new DeleteStorageCommand(storageId: $storage->id);

        $this->expectException(StorageCannotBeDeleted::class);
        $this->expectExceptionMessage('obsazený');

        ($this->handler)($command);
    }

    public function testCannotDeleteReservedStorage(): void
    {
        // Storage B1 is RESERVED (has order in reserved status from OrderFixtures)
        /** @var Storage $storage */
        $storage = $this->getFixtureReference(StorageFixtures::REF_MEDIUM_B1);

        $this->assertTrue($storage->isReserved(), 'Storage B1 should be reserved');

        $command = new DeleteStorageCommand(storageId: $storage->id);

        $this->expectException(StorageCannotBeDeleted::class);
        $this->expectExceptionMessage('rezervaci');

        ($this->handler)($command);
    }

    public function testCanDeleteManuallyUnavailableStorage(): void
    {
        // Storage A2 is available, we'll mark it as manually unavailable
        /** @var Storage $storage */
        $storage = $this->getFixtureReference(StorageFixtures::REF_SMALL_A2);
        $storage->markUnavailable(new \DateTimeImmutable());
        $this->entityManager->flush();

        $storageId = $storage->id;

        // Manually unavailable is different from occupied/reserved - it should be deletable
        $command = new DeleteStorageCommand(storageId: $storageId);
        ($this->handler)($command);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->expectException(StorageNotFound::class);
        $this->storageRepository->get($storageId);
    }

    private function getFixtureReference(string $name): object
    {
        return $this->entityManager
            ->getRepository(Storage::class)
            ->findOneBy(['number' => $this->getStorageNumberFromRef($name)]);
    }

    private function getStorageNumberFromRef(string $ref): string
    {
        // Convert ref like 'storage-small-a1' to 'A1'
        $matches = [];
        if (preg_match('/-([a-z])(\d+)$/', $ref, $matches)) {
            return strtoupper($matches[1]) . $matches[2];
        }

        throw new \InvalidArgumentException("Cannot parse storage number from reference: {$ref}");
    }
}
