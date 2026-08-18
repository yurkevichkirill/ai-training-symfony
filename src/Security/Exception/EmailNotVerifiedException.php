<?php

declare(strict_types=1);

namespace App\Security\Exception;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * The account exists and the password was right, but its email address has
 * never been verified, and S1 requires verification before a first sign-in
 * (resolved question Q-01.05).
 *
 * Distinct from AccountDeactivatedException only in class identity -- see that
 * class for why the distinction is kept server-side and erased in the response.
 */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('This account\'s email address has not been verified.');
    }

    public function getMessageKey(): string
    {
        return 'Email address is not verified.';
    }
}
