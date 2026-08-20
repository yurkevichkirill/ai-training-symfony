<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by FileStorage::store() when the upload exceeds the configured
 * size cap (AC-12).
 */
final class FileTooLargeException extends \RuntimeException
{
    public function __construct(private readonly int $maxBytes, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('The file exceeds the maximum size of %d bytes.', $maxBytes), previous: $previous);
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }
}
