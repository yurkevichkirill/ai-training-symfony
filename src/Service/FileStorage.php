<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Exception\FileTooLargeException;
use App\Service\Exception\UnsupportedFileTypeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Validates and stores an uploaded file under `%kernel.project_dir%/var/uploads`
 * -- deliberately **outside** `public/`, so nothing is directly served by
 * nginx without going through `PhotoController`'s own authorization check
 * (AC-12: "not a directly browsable filesystem path guessable from another
 * user's id").
 *
 * The returned key is opaque (`<prefix>/<random-hex>.<ext>`) and is what
 * `User::$photoKey` stores -- never a path a client constructs itself.
 */
final class FileStorage
{
    /** @var array<string, string> real, content-sniffed MIME type -> file extension */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly string $uploadsDir)
    {
    }

    /**
     * @throws FileTooLargeException
     * @throws UnsupportedFileTypeException
     */
    public function store(UploadedFile $file, string $prefix): string
    {
        $size = $file->getSize();

        if (null === $size || $size > self::MAX_BYTES) {
            throw new FileTooLargeException(self::MAX_BYTES);
        }

        // getMimeType() is content-sniffed (finfo-backed), not derived from
        // the client-supplied filename/extension -- what makes the
        // spoofed-extension edge case fail closed.
        $mimeType = $file->getMimeType();
        $extension = self::ALLOWED_MIME_TYPES[$mimeType] ?? null;

        if (null === $extension) {
            throw new UnsupportedFileTypeException($mimeType ?? 'unknown');
        }

        $key = \sprintf('%s/%s.%s', $prefix, bin2hex(random_bytes(16)), $extension);
        $file->move($this->uploadsDir.'/'.$prefix, basename($key));

        return $key;
    }

    public function read(string $key): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->uploadsDir.'/'.$key);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }

    public function delete(string $key): void
    {
        $path = $this->uploadsDir.'/'.$key;

        if (is_file($path)) {
            unlink($path);
        }
    }
}
