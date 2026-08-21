<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Exception\FileTooLargeException;
use App\Service\Exception\UnsupportedFileTypeException;
use App\Service\FileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Task 5's verification gate: `FileStorage::store()`'s two new trailing
 * parameters (Task 4) are optional and defaulted to `null`, so a call with
 * no third/fourth argument -- exactly `ProfileService::uploadPhoto()`'s
 * existing `store($file, 'photos')` text -- must behave byte-identically to
 * before this slice. Task 6 onward must not start until this is green.
 */
final class FileStorageRegressionTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->uploadsDir = sys_get_temp_dir().'/file-storage-regression-'.bin2hex(random_bytes(8));
        mkdir($this->uploadsDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadsDir);
    }

    public function testCallWithNoOptionalArgumentsStoresAFileWithinTheOldBounds(): void
    {
        $storage = new FileStorage($this->uploadsDir);
        $file = $this->pngUploadedFile(1024);

        $key = $storage->store($file, 'photos');

        self::assertMatchesRegularExpression('#^photos/[0-9a-f]{32}\.png$#', $key);
        self::assertFileExists($this->uploadsDir.'/'.$key);
    }

    public function testCallWithNoOptionalArgumentsStillEnforcesTheOldFiveMegabyteCap(): void
    {
        $storage = new FileStorage($this->uploadsDir);
        $file = $this->pngUploadedFile(6 * 1024 * 1024);

        $this->expectException(FileTooLargeException::class);

        $storage->store($file, 'photos');
    }

    public function testCallWithNoOptionalArgumentsStillEnforcesTheOldMimeAllowList(): void
    {
        $storage = new FileStorage($this->uploadsDir);
        $file = $this->gifUploadedFile();

        $this->expectException(UnsupportedFileTypeException::class);

        $storage->store($file, 'photos');
    }

    public function testExplicitNullArgumentsBehaveIdenticallyToOmittingThem(): void
    {
        $storage = new FileStorage($this->uploadsDir);
        $file = $this->pngUploadedFile(1024);

        $key = $storage->store($file, 'photos', null, null);

        self::assertMatchesRegularExpression('#^photos/[0-9a-f]{32}\.png$#', $key);
    }

    private function pngUploadedFile(int $size): UploadedFile
    {
        // A minimal, valid 1x1 PNG header/body, padded to the requested
        // size -- so FileStorage's content-sniffed MIME check (finfo, not
        // the filename) genuinely sees `image/png`, exactly as the S2
        // photo-upload suite's own fixture does.
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($pngBytes);

        $path = tempnam(sys_get_temp_dir(), 'file-storage-regression-png-');
        self::assertNotFalse($path);
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        fwrite($handle, $pngBytes);

        if ($size > \strlen($pngBytes)) {
            fseek($handle, $size - 1);
            fwrite($handle, "\0");
        }

        fclose($handle);

        return new UploadedFile($path, 'photo.png', 'image/png', null, true);
    }

    private function gifUploadedFile(): UploadedFile
    {
        // GIF89a header -- content-sniffed as image/gif, not on the
        // allow-list, regardless of the filename extension we give it.
        $path = tempnam(sys_get_temp_dir(), 'file-storage-regression-gif-');
        self::assertNotFalse($path);
        file_put_contents($path, "GIF89a\x01\x00\x01\x00\x00\x00\x00;");

        return new UploadedFile($path, 'disguised.png', 'image/gif', null, true);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
