<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by FileStorage::store() when the upload's real, content-sniffed
 * MIME type (Symfony UploadedFile::getMimeType(), finfo-backed) is not on
 * the allow-list -- never based on the filename extension, so a script
 * renamed `.jpg` is still rejected (AC-12's edge case).
 */
final class UnsupportedFileTypeException extends \RuntimeException
{
    public function __construct(private readonly string $mimeType, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('Unsupported file type "%s".', $mimeType), previous: $previous);
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }
}
