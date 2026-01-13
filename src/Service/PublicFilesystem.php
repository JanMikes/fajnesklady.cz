<?php

declare(strict_types=1);

namespace App\Service;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PublicFilesystem
{
    public function __construct(
        private FilesystemOperator $publicStorage,
    ) {
    }

    /**
     * Write content to a file.
     *
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $contents): void
    {
        $this->publicStorage->write($path, $contents);
    }

    /**
     * Write an uploaded file to the filesystem.
     *
     * @throws UnableToWriteFile
     */
    public function writeUploadedFile(UploadedFile $file, string $path): void
    {
        $stream = fopen($file->getPathname(), 'rb');

        if (false === $stream) {
            throw new UnableToWriteFile(sprintf('Unable to open file: %s', $file->getPathname()));
        }

        try {
            $this->publicStorage->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Read file contents.
     *
     * @throws UnableToReadFile
     */
    public function read(string $path): string
    {
        return $this->publicStorage->read($path);
    }

    /**
     * Delete a file.
     *
     * @throws UnableToDeleteFile
     */
    public function delete(string $path): void
    {
        $this->publicStorage->delete($path);
    }

    /**
     * Delete a file if it exists, silently ignore if it doesn't.
     */
    public function deleteIfExists(string $path): void
    {
        if ($this->exists($path)) {
            $this->publicStorage->delete($path);
        }
    }

    /**
     * Check if a file exists.
     */
    public function exists(string $path): bool
    {
        return $this->publicStorage->fileExists($path);
    }

    /**
     * Get the MIME type of a file.
     */
    public function mimeType(string $path): string
    {
        return $this->publicStorage->mimeType($path);
    }

    /**
     * Get the size of a file in bytes.
     */
    public function fileSize(string $path): int
    {
        return $this->publicStorage->fileSize($path);
    }
}
