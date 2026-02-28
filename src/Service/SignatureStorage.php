<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Uid\Uuid;

final readonly class SignatureStorage
{
    public function __construct(
        private string $signaturesDirectory,
    ) {
    }

    public function store(Uuid $orderId, string $dataUrl): string
    {
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new \InvalidArgumentException('Invalid signature data URL: must be a base64-encoded PNG.');
        }

        $base64Data = substr($dataUrl, strlen('data:image/png;base64,'));
        $binaryData = base64_decode($base64Data, true);

        if (false === $binaryData) {
            throw new \InvalidArgumentException('Invalid base64 data in signature.');
        }

        // Validate PNG magic bytes
        if (!str_starts_with($binaryData, "\x89PNG")) {
            throw new \InvalidArgumentException('Invalid PNG data in signature.');
        }

        if (!is_dir($this->signaturesDirectory)) {
            mkdir($this->signaturesDirectory, 0755, true);
        }

        $filename = sprintf('signature_%s.png', $orderId->toRfc4122());
        $path = $this->signaturesDirectory.'/'.$filename;

        file_put_contents($path, $binaryData);

        return $path;
    }
}
