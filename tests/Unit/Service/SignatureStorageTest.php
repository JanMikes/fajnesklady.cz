<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SignatureStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SignatureStorageTest extends TestCase
{
    private string $tempDir;
    private SignatureStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/signature_test_'.uniqid();
        $this->storage = new SignatureStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testStoreCreatesDirectoryAndFile(): void
    {
        $orderId = Uuid::v7();
        $dataUrl = $this->createValidPngDataUrl();

        $path = $this->storage->store($orderId, $dataUrl);

        $this->assertDirectoryExists($this->tempDir);
        $this->assertFileExists($path);
        $this->assertStringContainsString('signature_'.$orderId->toRfc4122().'.png', $path);
    }

    public function testStoreCreatesValidPngFile(): void
    {
        $orderId = Uuid::v7();
        $dataUrl = $this->createValidPngDataUrl();

        $path = $this->storage->store($orderId, $dataUrl);

        $content = file_get_contents($path);
        \assert($content !== false);
        $this->assertStringStartsWith("\x89PNG", $content);
    }

    public function testStoreReturnsFullPath(): void
    {
        $orderId = Uuid::v7();
        $dataUrl = $this->createValidPngDataUrl();

        $path = $this->storage->store($orderId, $dataUrl);

        $expectedPath = $this->tempDir.'/signature_'.$orderId->toRfc4122().'.png';
        $this->assertSame($expectedPath, $path);
    }

    public function testStoreThrowsExceptionForInvalidPrefix(): void
    {
        $orderId = Uuid::v7();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid signature data URL: must be a base64-encoded PNG.');

        $this->storage->store($orderId, 'data:image/jpeg;base64,abc123');
    }

    public function testStoreThrowsExceptionForMissingDataUrlPrefix(): void
    {
        $orderId = Uuid::v7();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid signature data URL: must be a base64-encoded PNG.');

        $this->storage->store($orderId, 'not-a-data-url');
    }

    public function testStoreThrowsExceptionForInvalidBase64(): void
    {
        $orderId = Uuid::v7();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid base64 data in signature.');

        // Invalid base64 characters
        $this->storage->store($orderId, 'data:image/png;base64,!!!invalid!!!');
    }

    public function testStoreThrowsExceptionForNonPngData(): void
    {
        $orderId = Uuid::v7();
        // Valid base64 but not PNG data (JPEG magic bytes)
        $jpegBytes = "\xFF\xD8\xFF\xE0some data";
        $base64 = base64_encode($jpegBytes);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PNG data in signature.');

        $this->storage->store($orderId, 'data:image/png;base64,'.$base64);
    }

    public function testStoreOverwritesExistingFile(): void
    {
        $orderId = Uuid::v7();
        $dataUrl = $this->createValidPngDataUrl();

        $path1 = $this->storage->store($orderId, $dataUrl);
        $path2 = $this->storage->store($orderId, $dataUrl);

        $this->assertSame($path1, $path2);
        $this->assertFileExists($path2);
    }

    public function testStoreCreatesNestedDirectories(): void
    {
        $nestedDir = $this->tempDir.'/nested/signatures';
        $storage = new SignatureStorage($nestedDir);
        $orderId = Uuid::v7();
        $dataUrl = $this->createValidPngDataUrl();

        $path = $storage->store($orderId, $dataUrl);

        $this->assertDirectoryExists($nestedDir);
        $this->assertFileExists($path);
    }

    private function createValidPngDataUrl(): string
    {
        // Create a minimal 1x1 white PNG image
        $image = imagecreatetruecolor(1, 1);
        $white = imagecolorallocate($image, 255, 255, 255);
        \assert($white !== false);
        imagefill($image, 0, 0, $white);

        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        \assert($pngData !== false);

        return 'data:image/png;base64,'.base64_encode($pngData);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
