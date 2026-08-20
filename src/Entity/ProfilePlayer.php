<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PlayerGender;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's self-declared identity fields captured at ShareLink
 * registration (AC-7): `playerName`, `declaredAge`, `gender`. The second
 * concrete subtype of S1's frozen Profile/JOINED hierarchy, added exactly as
 * S2 said the remaining subtypes would be -- "additive migrations for
 * S3/S4/S5 to write when they have real columns" (see `ProfileTrainer`).
 *
 * `declaredAge` is stored as submitted, dated by `Profile::createdAt`,
 * rather than synthesizing a date of birth from it -- a birthday would be
 * invented, wrong by up to a year (architecture Decisions, "Age
 * representation").
 *
 * The shape most likely to change when Parent/Child lands (architecture
 * Risks): keep these three columns out of every query except the roster
 * display and the registration write, so that later migration touches two
 * call sites rather than twenty.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_player')]
class ProfilePlayer extends Profile
{
    #[ORM\Column(name: 'player_name', type: 'string', length: 160)]
    private string $playerName;

    #[ORM\Column(name: 'declared_age', type: 'smallint')]
    private int $declaredAge;

    #[ORM\Column(type: 'string', length: 32, enumType: PlayerGender::class)]
    private PlayerGender $gender;

    public function __construct(
        User $user,
        string $playerName,
        int $declaredAge,
        PlayerGender $gender,
        ?\DateTimeImmutable $now = null,
    ) {
        parent::__construct($user, $now);
        $this->playerName = $playerName;
        $this->declaredAge = $declaredAge;
        $this->gender = $gender;
    }

    public function getPlayerName(): string
    {
        return $this->playerName;
    }

    public function setPlayerName(string $playerName): void
    {
        $this->playerName = $playerName;
    }

    public function getDeclaredAge(): int
    {
        return $this->declaredAge;
    }

    public function setDeclaredAge(int $declaredAge): void
    {
        $this->declaredAge = $declaredAge;
    }

    public function getGender(): PlayerGender
    {
        return $this->gender;
    }

    public function setGender(PlayerGender $gender): void
    {
        $this->gender = $gender;
    }
}
