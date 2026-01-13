<?php

declare(strict_types=1);

namespace App\Controller\Api;

trait StorageApiValidationTrait
{
    /**
     * @param array<string, mixed>|null $data
     */
    private function validateStorageData(?array $data): bool
    {
        if (null === $data) {
            return false;
        }

        if (empty($data['number']) || empty($data['storageTypeId']) || empty($data['coordinates'])) {
            return false;
        }

        $coords = $data['coordinates'];
        if (!isset($coords['x'], $coords['y'], $coords['width'], $coords['height'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $coordinates
     *
     * @return array{x: int, y: int, width: int, height: int, rotation: int}
     */
    private function sanitizeCoordinates(array $coordinates): array
    {
        return [
            'x' => (int) ($coordinates['x'] ?? 0),
            'y' => (int) ($coordinates['y'] ?? 0),
            'width' => (int) ($coordinates['width'] ?? 100),
            'height' => (int) ($coordinates['height'] ?? 100),
            'rotation' => (int) ($coordinates['rotation'] ?? 0),
        ];
    }
}
