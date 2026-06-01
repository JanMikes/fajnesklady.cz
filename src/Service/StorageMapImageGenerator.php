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

readonly class StorageMapImageGenerator
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

        $image = $this->imageManager->decode($imageData);

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

        $width = (float) $coords['width'];
        $height = (float) $coords['height'];

        if ($width <= 0.0 || $height <= 0.0) {
            return;
        }

        $corners = self::rotatedCorners(
            (float) $coords['x'],
            (float) $coords['y'],
            $width,
            $height,
            (float) $coords['rotation'],
        );

        // Keep the existing highlighted / dimmed styling untouched.
        [$background, $border, $borderWidth] = $isHighlighted
            ? ['rgba(34, 197, 94, 0.4)', 'rgba(22, 163, 74, 1.0)', 3]
            : ['rgba(156, 163, 175, 0.3)', 'rgba(107, 114, 128, 0.5)', 1];

        $image->drawPolygon(function ($polygon) use ($corners, $background, $border, $borderWidth): void {
            foreach ($corners as [$px, $py]) {
                $polygon->point($px, $py);
            }
            $polygon->background($background);
            $polygon->border($border, $borderWidth);
        });
    }

    /**
     * Four corners (clockwise from top-left) of a rectangle rotated around its center,
     * matching the Konva transform used by the interactive picker
     * (assets/controllers/storage_map_controller.js:465). Rotation is in degrees,
     * clockwise in image (y-down) space — the same standard matrix Konva applies.
     *
     * @return list<array{int, int}>
     */
    public static function rotatedCorners(
        float $x,
        float $y,
        float $width,
        float $height,
        float $rotationDegrees,
    ): array {
        $centerX = $x + $width / 2;
        $centerY = $y + $height / 2;
        $halfWidth = $width / 2;
        $halfHeight = $height / 2;

        $rad = deg2rad($rotationDegrees);
        $cos = cos($rad);
        $sin = sin($rad);

        // Offsets from center, clockwise: top-left, top-right, bottom-right, bottom-left.
        $offsets = [
            [-$halfWidth, -$halfHeight],
            [$halfWidth, -$halfHeight],
            [$halfWidth, $halfHeight],
            [-$halfWidth, $halfHeight],
        ];

        $corners = [];
        foreach ($offsets as [$offsetX, $offsetY]) {
            $rotatedX = $offsetX * $cos - $offsetY * $sin;
            $rotatedY = $offsetX * $sin + $offsetY * $cos;
            $corners[] = [
                (int) round($centerX + $rotatedX),
                (int) round($centerY + $rotatedY),
            ];
        }

        return $corners;
    }
}
