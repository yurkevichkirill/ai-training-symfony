<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Profile;
use Doctrine\ORM\Mapping as ORM;

/**
 * A trainer's business details (US-01.14 business-facing fields; AC-11).
 *
 * The organization anchor other slices attach owned data to (S3's
 * TrainerPlayerAssociation, S3's coach association, S8's branding) -- see
 * `specs/sdd-user-management-architecture.md` Decisions for why full
 * multi-tenancy scoping is deferred to S3 rather than guessed here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_trainer')]
class ProfileTrainer extends Profile
{
    #[ORM\Column(name: 'business_name', type: 'string', length: 160)]
    private string $businessName;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * S7 (Trainer Portal Branding): the opaque `FileStorage` key
     * (`branding/<32-hex>.<ext>`) for this trainer's uploaded logo. `NULL`
     * is the "never uploaded" state -- the platform placeholder renders,
     * never a broken `<img>`.
     */
    #[ORM\Column(name: 'logo_key', type: 'string', length: 255, nullable: true)]
    private ?string $logoKey = null;

    /**
     * S7: `#rrggbb`, lowercase. `NULL` is "no override" -- the stylesheet's
     * `--color-primary` default stands. A database CHECK (this slice's
     * migration) is the third layer of this invariant, alongside the DTO's
     * normalisation and the form's `Regex` constraint.
     */
    #[ORM\Column(name: 'primary_color_hex', type: 'string', length: 7, nullable: true)]
    private ?string $primaryColorHex = null;

    public function __construct(User $user, string $businessName, ?\DateTimeImmutable $now = null)
    {
        parent::__construct($user, $now);
        $this->businessName = $businessName;
    }

    public function getBusinessName(): string
    {
        return $this->businessName;
    }

    public function setBusinessName(string $businessName): void
    {
        $this->businessName = $businessName;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): void
    {
        $this->website = $website;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): void
    {
        $this->address = $address;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getLogoKey(): ?string
    {
        return $this->logoKey;
    }

    public function setLogoKey(?string $logoKey): void
    {
        $this->logoKey = $logoKey;
    }

    public function getPrimaryColorHex(): ?string
    {
        return $this->primaryColorHex;
    }

    public function setPrimaryColorHex(?string $primaryColorHex): void
    {
        $this->primaryColorHex = $primaryColorHex;
    }
}
