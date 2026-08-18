<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

/**
 * The persistence side of symfonycasts/reset-password-bundle.
 *
 * We own the class and its repository; the bundle owns the token crypto, the
 * selector/verifier split and the expiry check. ResetPasswordRequestTrait
 * supplies `selector`, `hashedToken`, `requestedAt`, `expiresAt` along with the
 * whole read side of ResetPasswordRequestInterface, so nothing here
 * reimplements what the bundle already guarantees (AC-9..AC-12).
 */
#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
#[ORM\Table(name: 'reset_password_request')]
#[ORM\Index(name: 'idx_reset_password_request_user', columns: ['user_id'])]
class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private readonly Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $user;

    public function __construct(User $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        $this->id = new UuidV7();
        $this->user = $user;

        // Sets requestedAt/expiresAt/selector/hashedToken; the trait owns them.
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
