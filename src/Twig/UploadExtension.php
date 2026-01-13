<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UploadExtension extends AbstractExtension
{
    private const UPLOADS_PATH = 'uploads/';

    public function __construct(
        private readonly Packages $packages,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('upload_url', $this->getUploadUrl(...)),
        ];
    }

    /**
     * Generate a public URL for an uploaded file.
     *
     * @param string|null $path Relative path within uploads directory (e.g., 'places/uuid/maps/file.png')
     */
    public function getUploadUrl(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }

        return $this->packages->getUrl(self::UPLOADS_PATH.$path);
    }
}
