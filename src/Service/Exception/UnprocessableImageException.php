<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `TrainerBrandingService::uploadLogo()` (AC-5) when
 * `getimagesize()` cannot parse the uploaded file, or either dimension
 * exceeds the 4000px sanity bound -- a second, independent decoder's
 * opinion on top of `FileStorage`'s finfo-backed MIME sniff, and a
 * decompression-bomb guard: a 40-megapixel PNG can sit well under the 2MB
 * cap.
 */
final class UnprocessableImageException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This image could not be processed.', previous: $previous);
    }
}
