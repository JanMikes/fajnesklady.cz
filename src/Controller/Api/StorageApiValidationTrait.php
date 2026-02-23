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
     * @return array{x: int|float, y: int|float, width: int|float, height: int|float, rotation: int|float, normalized?: bool}
     */
    private function sanitizeCoordinates(array $coordinates): array
    {
        $result = [
            'x' => (float) ($coordinates['x'] ?? 0),
            'y' => (float) ($coordinates['y'] ?? 0),
            'width' => (float) ($coordinates['width'] ?? 100),
            'height' => (float) ($coordinates['height'] ?? 100),
            'rotation' => (float) ($coordinates['rotation'] ?? 0),
        ];

        if (!empty($coordinates['normalized'])) {
            $result['normalized'] = true;
        }

        return $result;
    }
}
