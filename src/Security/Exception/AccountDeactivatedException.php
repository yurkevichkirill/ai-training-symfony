<?php

declare(strict_types=1);

namespace App\Security\Exception;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * The account exists and the password was right, but the account is not active.
 *
 * The message here is never what the caller sees -- UniformAuthenticationFailureHandler
 * collapses every failure to one message so the response cannot be used to
 * probe which accounts exist or what state they are in (AC-2, AC-3). The class
 * identity is what matters: it is how AuthEventSubscriber records *why* the
 * attempt failed in the audit trail (AC-24), which is information the operator
 * needs and the visitor must not have.
 */
final class AccountDeactivatedException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('This account has been deactivated.');
    }

    public function getMessageKey(): string
    {
        return 'Account is deactivated.';
    }
}
