<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Storage;
use App\Repository\StorageRepository;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;

final readonly class StorageMapImageGenerator
{
    public function __construct(
        private StorageRepository $storageRepository,
        private PublicFilesystem $filesystem,
        private ImageManager $imageManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generates a PNG image of the place map with the given storage highlighted.
     * Returns the PNG data as a string, or null if no map image is available.
     */
    public function generate(Storage $highlightedStorage): ?string
    {
        $place = $highlightedStorage->getPlace();

        if (null === $place->mapImagePath) {
            return null;
        }

        try {
            $imageData = $this->filesystem->read($place->mapImagePath);
        } catch (FilesystemException $e) {
            $this->logger->warning('Failed to read map image', ['path' => $place->mapImagePath, 'exception' => $e]);

            return null;
        }

        $image = $this->imageManager->read($imageData);

        $allStorages = $this->storageRepository->findByPlace($place);

        foreach ($allStorages as $storage) {
            $isHighlighted = $storage->id->equals($highlightedStorage->id);
            $this->drawStorage($image, $storage, $isHighlighted);
        }

        return $image->encode(new PngEncoder())->toString();
    }

    private function drawStorage(ImageInterface $image, Storage $storage, bool $isHighlighted): void
    {
        $coords = $storage->coordinates;

        if (!isset($coords['normalized']) || true !== $coords['normalized']) {
            return;
        }

        $x = (int) round((float) $coords['x']);
        $y = (int) round((float) $coords['y']);
        $width = (int) round((float) $coords['width']);
        $height = (int) round((float) $coords['height']);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        if ($isHighlighted) {
            $image->drawRectangle($x, $y, function ($rectangle) use ($width, $height): void {
                $rectangle->size($width, $height);
                $rectangle->background('rgba(34, 197, 94, 0.4)');
                $rectangle->border('rgba(22, 163, 74, 1.0)', 3);
            });
        } else {
            $image->drawRectangle($x, $y, function ($rectangle) use ($width, $height): void {
                $rectangle->size($width, $height);
                $rectangle->background('rgba(156, 163, 175, 0.3)');
                $rectangle->border('rgba(107, 114, 128, 0.5)', 1);
            });
        }
    }
}
