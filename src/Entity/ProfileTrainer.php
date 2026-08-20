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
}
