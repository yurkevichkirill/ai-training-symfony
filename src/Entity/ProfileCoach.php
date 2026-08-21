<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A coach's own profile fields (AC-11, AC-12, AC-13): free-text bio,
 * credentials, certifications, and a public-profile visibility checkbox.
 * The fourth concrete subtype of S1's frozen `Profile`/JOINED hierarchy,
 * added exactly as `Profile`'s own docblock already named it (`profile_coach`).
 *
 * **Created lazily, on first save** (D1c): no code path creates a
 * `ProfileCoach` today, and no backfill migration runs for coaches that
 * already exist -- `ProfileService::updateCoachDetails()` is the one writer
 * that creates this row when `ProfileRepository::findCoachProfile()` returns
 * null. Every reader must therefore tolerate "no `profile_coach` row at
 * all", which is also exactly how AC-16's "off when nothing has ever been
 * saved" is expressed: no row means not public, not a third state.
 *
 * `credentials`/`certifications` are free text (D1b), not a repeatable
 * structured list -- nothing in this slice reads an individual entry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_coach')]
class ProfileCoach extends Profile
{
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $credentials = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $certifications = null;

    #[ORM\Column(name: 'is_public', type: 'boolean')]
    private bool $isPublic = false;

    public function __construct(User $user, ?\DateTimeImmutable $now = null)
    {
        parent::__construct($user, $now);
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): void
    {
        $this->bio = $bio;
    }

    public function getCredentials(): ?string
    {
        return $this->credentials;
    }

    public function setCredentials(?string $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function getCertifications(): ?string
    {
        return $this->certifications;
    }

    public function setCertifications(?string $certifications): void
    {
        $this->certifications = $certifications;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
    }
}
