<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;

/**
 * Maps PostgreSQL's native `inet` column to a PHP string.
 *
 * The architecture calls for `auth_event.ip inet` rather than a varchar, and
 * DBAL ships no first-class type for it. Twenty lines here is cheaper than the
 * alternatives: a varchar column would lose PostgreSQL's own validation and its
 * network-aware operators (which the S6 audit reports will want), while a
 * `columnDefinition` override would keep the column out of Doctrine's schema
 * model and produce permanent diff noise. With a real type, `doctrine:schema:
 * validate` stays clean.
 *
 * Values are handled as strings on the PHP side -- PostgreSQL does the parsing
 * and rejects anything that is not an address, so no validation is duplicated
 * here beyond the null passthrough.
 */
final class InetType extends Type
{
    public const NAME = 'inet';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'INET';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', 'string']);
        }

        return $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
