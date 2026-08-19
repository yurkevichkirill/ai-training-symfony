<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by UserAccountService::create() when `app_user`'s
 * `UNIQUE (email)` constraint rejects the insert (AC-5).
 *
 * The database is the authoritative uniqueness check -- any
 * application-level pre-check (e.g. a `UniqueEntity` constraint on the
 * request DTO) is only a friendly fast path and can still lose a race to a
 * concurrent request. This exception is the typed translation of that
 * database-level rejection, for callers (controllers, console commands) to
 * map to a field-level error instead of letting a raw DBAL exception surface
 * as an uncaught 500.
 */
final class EmailAlreadyInUseException extends \RuntimeException
{
    public function __construct(private readonly string $email, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('An account with the email "%s" already exists.', $email), previous: $previous);
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
