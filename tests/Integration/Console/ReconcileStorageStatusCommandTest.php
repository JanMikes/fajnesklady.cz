<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\StorageStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class ReconcileStorageStatusCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

        $this->application = new Application(self::$kernel);
    }

    /**
     * A unit stuck `manually_unavailable` with NO backing block / order / contract
     * (the exact production drift) must be healed back to AVAILABLE.
     */
    public function testReconcilesOrphanedManuallyUnavailableBackToAvailable(): void
    {
        $storage = $this->createDriftedStorage('RECON-1', StorageStatus::MANUALLY_UNAVAILABLE);

        $this->runCommand();

        $this->entityManager->refresh($storage);
        self::assertSame(StorageStatus::AVAILABLE, $storage->status);
    }

    /**
     * A unit stuck `occupied` with no active contract (a missed release) is the
     * other drift class — it must also heal back to AVAILABLE.
     */
    public function testReconcilesOrphanedOccupiedBackToAvailable(): void
    {
        $storage = $this->createDriftedStorage('RECON-2', StorageStatus::OCCUPIED);

        $tester = $this->runCommand();

        $this->entityManager->refresh($storage);
        self::assertSame(StorageStatus::AVAILABLE, $storage->status);
        self::assertStringContainsString('Reconciled', $tester->getDisplay());
    }

    private function createDriftedStorage(string $number, StorageStatus $drifted): Storage
    {
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);

        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $storageType->place,
            createdAt: new \DateTimeImmutable('2025-01-01'),
        );

        // Force the stale/denormalized status without any backing booking row.
        $storage->reconcileStatusTo($drifted, new \DateTimeImmutable('2025-01-01'));

        $this->entityManager->persist($storage);
        $this->entityManager->flush();

        return $storage;
    }

    private function runCommand(): CommandTester
    {
        $command = $this->application->find('app:reconcile-storage-status');
        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }
}
