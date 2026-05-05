<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\StorageType;
use App\Entity\StorageTypePhoto;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Copies pre-generated placeholder JPGs from fixtures/photos/ into
 * public/uploads/storage-types/{uuid}/photos/{filename} and persists matching
 * StorageTypePhoto rows. Some types are intentionally left without photos to
 * exercise the "no photos" branch of the photo_gallery partial.
 *
 * Run `bin/console app:generate-fixture-photos` to (re)create the source JPGs.
 */
final class StorageTypePhotoFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * @var array<string, list<string>>
     */
    private const PHOTOS = [
        StorageTypeFixtures::REF_SMALL_CENTRUM => [
            'box-blue-landscape.jpg',
        ],
        StorageTypeFixtures::REF_MEDIUM_CENTRUM => [
            'box-orange-wide.jpg',
            'box-gray-portrait.jpg',
            'box-green-square.jpg',
        ],
        StorageTypeFixtures::REF_LARGE_CENTRUM => [
            'container-red.jpg',
            'container-teal.jpg',
        ],
        StorageTypeFixtures::REF_CUSTOM_CENTRUM => [
            'interior-purple.jpg',
            'interior-yellow-portrait.jpg',
            'box-blue-landscape.jpg',
            'box-orange-wide.jpg',
        ],
        StorageTypeFixtures::REF_MEDIUM_JIH => [
            'container-teal.jpg',
        ],
        StorageTypeFixtures::REF_PREMIUM_BRNO => [
            'container-red.jpg',
            'container-teal.jpg',
            'interior-purple.jpg',
            'interior-yellow-portrait.jpg',
            'box-green-square.jpg',
        ],
        // REF_SMALL_JIH and REF_STANDARD_OSTRAVA intentionally omitted.
    ];

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $now = $this->clock->now();
        $sourceDir = __DIR__.'/photos';
        $uploadsDir = __DIR__.'/../public/uploads';

        foreach (self::PHOTOS as $ref => $filenames) {
            /** @var StorageType $storageType */
            $storageType = $this->getReference($ref, StorageType::class);

            foreach ($filenames as $position => $filename) {
                $relativePath = sprintf(
                    'storage-types/%s/photos/%s',
                    $storageType->id->toRfc4122(),
                    $filename,
                );

                $this->copyFixturePhoto(
                    $sourceDir.'/'.$filename,
                    $uploadsDir.'/'.$relativePath,
                );

                $photo = new StorageTypePhoto(
                    id: Uuid::v7(),
                    storageType: $storageType,
                    path: $relativePath,
                    position: $position,
                    createdAt: $now,
                );
                $manager->persist($photo);
                $storageType->addPhoto($photo);
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
            StorageTypeFixtures::class,
        ];
    }

    private function copyFixturePhoto(string $source, string $destination): void
    {
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Fixture photo not found: %s. Run `docker compose exec web bin/console app:generate-fixture-photos` to (re)generate.', $source));
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
