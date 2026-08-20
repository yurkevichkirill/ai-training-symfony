<?php

declare(strict_types=1);

namespace App\Service\Exception;

/**
 * Thrown by `CoachInvitationService::accept()` (AC-21) when the signed-in
 * account completing the link is not a `COACH`, or its (already normalized)
 * email does not equal the invitation's `invitedEmail`. This is what refuses
 * the "signs in as a different email" and "signed-in Player follows a coach
 * link" edge cases -- a coach ShareLink only ever completes for the address
 * it was issued to.
 */
final class CoachInvitationEmailMismatchException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('This invitation was sent to a different email address.', previous: $previous);
    }
}
