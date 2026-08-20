<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Task 36 (AC-11 amendment, "Post-implementation hardening decisions" in
 * `specs/sdd-sharelink-invitations-architecture.md`): gives a player a way
 * to leave a trainer, so AC-11's no-confirmation `/join/{code}` GET-write is
 * no longer a permanent, unconsented PII disclosure.
 *
 * Generated with doctrine:migrations:diff, then reformatted to this
 * project's one-`addSql`-call-per-statement, single-line style (matching
 * every other migration in this project) -- no hand-finishing was otherwise
 * needed. The partial unique index needed no hand-written line: the diff
 * emitted `CREATE UNIQUE INDEX uniq_trainer_player_active_association ON
 * trainer_player_association (trainer_id, player_id) WHERE (ended_at IS
 * NULL)` straight from `TrainerPlayerAssociation`'s
 * `#[ORM\UniqueConstraint(..., options: ['where' => '(ended_at IS NULL)'])]`
 * -- the same parenthesized-predicate shape already proven not to
 * perpetually diff on `TrainerCoachAssociation`'s `uniq_trainer_coach_active_coach`
 * (Version20260820095413's docblock), confirmed again here by running
 * `doctrine:schema:update --dump-sql` twice after this migration and getting
 * "Nothing to update" both times.
 */
final class Version20260820131012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player leave/revoke path (Task 36, AC-11 amendment): trainer_player_association.ended_at + partial unique index (trainer_id, player_id) WHERE ended_at IS NULL.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_trainer_player_association');
        $this->addSql('ALTER TABLE trainer_player_association ADD ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_trainer_player_association_trainer_ended ON trainer_player_association (trainer_id, ended_at)');
        $this->addSql('CREATE INDEX idx_trainer_player_association_player_ended ON trainer_player_association (player_id, ended_at)');
        // Task 36's core schema change: an ended row (ended_at IS NOT NULL)
        // is invisible to this index, which is exactly what lets a player
        // leave and later rejoin the same trainer's link without
        // resurrecting a stale row -- mirroring trainer_coach_association's
        // uniq_trainer_coach_active_coach (AC-16) exactly.
        $this->addSql('CREATE UNIQUE INDEX uniq_trainer_player_active_association ON trainer_player_association (trainer_id, player_id) WHERE (ended_at IS NULL)');
    }

    /**
     * Reverse of up(), in reverse order. Dropping ended_at loses no
     * currently-active row's data (they all have ended_at = NULL); any
     * ended row's "left this trainer" fact is what is lost on a rollback,
     * which is the expected cost of reverting this migration.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_trainer_player_association_trainer_ended');
        $this->addSql('DROP INDEX idx_trainer_player_association_player_ended');
        $this->addSql('DROP INDEX uniq_trainer_player_active_association');
        $this->addSql('ALTER TABLE trainer_player_association DROP ended_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_trainer_player_association ON trainer_player_association (trainer_id, player_id)');
    }
}
