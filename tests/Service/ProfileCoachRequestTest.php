<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ProfileCoachRequest;
use PHPUnit\Framework\TestCase;

/**
 * Task 31 (AC-16, edge case 5): whitespace-only and empty-string inputs for
 * bio/credentials/certifications all normalize to `null`; non-blank input is
 * trimmed and preserved.
 */
final class ProfileCoachRequestTest extends TestCase
{
    public function testEmptyStringFieldsNormalizeToNull(): void
    {
        $request = new ProfileCoachRequest('', '', '');

        self::assertNull($request->bio);
        self::assertNull($request->credentials);
        self::assertNull($request->certifications);
    }

    public function testWhitespaceOnlyFieldsNormalizeToNull(): void
    {
        $request = new ProfileCoachRequest("   \t\n", '   ', "\n\n");

        self::assertNull($request->bio);
        self::assertNull($request->credentials);
        self::assertNull($request->certifications);
    }

    public function testNullFieldsRemainNull(): void
    {
        $request = new ProfileCoachRequest(null, null, null);

        self::assertNull($request->bio);
        self::assertNull($request->credentials);
        self::assertNull($request->certifications);
    }

    public function testNonBlankInputIsTrimmedAndPreserved(): void
    {
        $request = new ProfileCoachRequest(
            '  Loves coaching kids.  ',
            "\tUSSF B License\n",
            '  CPR Certified  ',
        );

        self::assertSame('Loves coaching kids.', $request->bio);
        self::assertSame('USSF B License', $request->credentials);
        self::assertSame('CPR Certified', $request->certifications);
    }

    public function testIsPublicDefaultsToFalse(): void
    {
        $request = new ProfileCoachRequest(null, null, null);

        self::assertFalse($request->isPublic);
    }

    public function testIsPublicIsPreservedWhenPassedTrue(): void
    {
        $request = new ProfileCoachRequest(null, null, null, true);

        self::assertTrue($request->isPublic);
    }
}
