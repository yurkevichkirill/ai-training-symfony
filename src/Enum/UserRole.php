<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The single role a user holds.
 *
 * A user has exactly one of these, stored as a scalar column -- never a list.
 * Multi-role capability is expressed by attaching profiles to the account
 * (the frozen User<->Profile contract), not by widening this enum into an
 * array, so "a second role" is unrepresentable at the type level. The schema
 * CHECK constraint on app_user.role enforces the same thing at the storage
 * level (AC-15).
 *
 * The backing values are the Symfony role strings themselves, so
 * User::getRoles() can return [$this->role->value] with no mapping table.
 * ROLE_USER is deliberately absent: it comes from role_hierarchy, not from
 * the entity.
 */
enum UserRole: string
{
    case SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    case TRAINER = 'ROLE_TRAINER';
    case COACH = 'ROLE_COACH';
    case PLAYER = 'ROLE_PLAYER';
}
