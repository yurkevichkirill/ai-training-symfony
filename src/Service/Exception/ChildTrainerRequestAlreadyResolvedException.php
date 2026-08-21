<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `ChildTrainerService::approveRequest()`/`dismissRequest()` when
 * the given `ChildTrainerRequest` has already been resolved (`APPROVED` or
 * `DISMISSED`). An invalid state transition is a typed exception here, not a
 * silent re-resolve -- the same convention `NoActiveTrainerAssociationException`
 * and `InvalidAccountStateTransitionException` already establish throughout
 * this project. The realistic trigger is a parent double-clicking
 * "Approve"/"Dismiss" from two open tabs, or revisiting the review page
 * after the request was already acted on (the edge case: approving an
 * already-resolved request twice).
 */
final class ChildTrainerRequestAlreadyResolvedException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This request has already been resolved.', previous: $previous);
    }
}
