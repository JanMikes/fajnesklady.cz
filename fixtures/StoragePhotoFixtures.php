<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Storage;
use App\Entity\StoragePhoto;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit-specific photos for individual Storage rows. Only attached to a few
 * non-uniform units so the order form's "Fotografie skladu č. {N}" gallery
 * has data when the customer picks a specific unit from the map.
 */
final class StoragePhotoFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * @var array<string, list<string>>
     */
    private const PHOTOS = [
        StorageFixtures::REF_CUSTOM_X1 => [
            'interior-purple.jpg',
            'box-green-square.jpg',
        ],
        StorageFixtures::REF_CUSTOM_X2 => [
            'interior-yellow-portrait.jpg',
        ],
        // REF_CUSTOM_X3 intentionally omitted.
    ];

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $sourceDir = __DIR__ . '/photos';
        $uploadsDir = __DIR__ . '/../public/uploads';

        foreach (self::PHOTOS as $ref => $filenames) {
            /** @var Storage $storage */
            $storage = $this->getReference($ref, Storage::class);

            foreach ($filenames as $position => $filename) {
                $relativePath = sprintf(
                    'storages/%s/photos/%s',
                    $storage->id->toRfc4122(),
                    $filename,
                );

                $this->copyFixturePhoto(
                    $sourceDir . '/' . $filename,
                    $uploadsDir . '/' . $relativePath,
                );

                $photo = new StoragePhoto(
                    id: Uuid::v7(),
                    storage: $storage,
                    path: $relativePath,
                    position: $position,
                    createdAt: $now,
                );
                $manager->persist($photo);
                $storage->addPhoto($photo);
            }
        }

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            StorageFixtures::class,
        ];
    }

    private function copyFixturePhoto(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf(
                'Fixture photo not found: %s. Run `docker compose exec web bin/console app:generate-fixture-photos` to (re)generate.',
                $source,
            ));
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory %s', $directory));
        }

        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Failed to copy %s to %s', $source, $destination));
        }
    }
}
