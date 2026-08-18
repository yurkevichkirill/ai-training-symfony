<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\UserRole;

/**
 * The single place that answers "where does this role land after sign-in"
 * (AC-16).
 *
 * One resolver rather than a redirect decision scattered through controllers:
 * the mapping is total over UserRole, so adding a fifth role becomes a compile
 * -time-visible gap here instead of a silent fall-through to somebody else's
 * dashboard.
 */
final class RoleLandingResolver
{
    public function routeFor(UserRole $role): string
    {
        return match ($role) {
            UserRole::SUPER_ADMIN => 'admin_dashboard',
            UserRole::TRAINER => 'trainer_dashboard',
            UserRole::COACH => 'coach_dashboard',
            UserRole::PLAYER => 'player_dashboard',
        };
    }
}
