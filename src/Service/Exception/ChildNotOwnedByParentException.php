<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `ChildTrainerService::connect()`/`disconnect()` (and, by
 * extension, `approveRequest()`/`dismissRequest()`, which resolve to
 * `connect()` and re-check the request's own parent) when the acting
 * "parent" does not actually own the given `ChildAccount` -- a plain
 * service-level re-check beyond `FamilyVoter::MANAGE_CHILD` (not built yet
 * in this batch; a later one), per S3 Decision Q4's defence-in-depth rule:
 * every deny-list rule in this project exists as a voter *and* a service
 * guard, since a voter alone cannot cover a console command or a forged
 * request that never passes through an annotated action.
 *
 * Not listed among this batch's boundaries as a new file up front, but
 * required for `connect()`/`disconnect()` to have *some* typed refusal for
 * this guard rather than a bare `\RuntimeException` -- see this batch's
 * report for the explicit call-out.
 */
final class ChildNotOwnedByParentException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This account does not belong to your family.', previous: $previous);
    }
}
