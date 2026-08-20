<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ShareLink Invitations (Epic-01, slice S3): player_share_link,
 * coach_invitation, trainer_player_association, trainer_coach_association,
 * and profile_player (the second Profile subtype).
 *
 * Generated with doctrine:migrations:diff, then hand-finished for the two
 * CHECK constraints DBAL does not diff: coach_invitation's
 * `invited_email = lower(invited_email)` (mirroring app_user's own email
 * CHECK) and profile_player's `gender IN (...)` (mirroring app_user.role's).
 * The partial unique index on trainer_coach_association (coach_id) WHERE
 * ended_at IS NULL needed no hand-written line -- the diff emitted it
 * correctly straight from `TrainerCoachAssociation`'s
 * `#[ORM\UniqueConstraint(..., options: ['where' => ...])]`, confirming the
 * architecture doc's Risks entry ("verified, not a risk").
 */
final class Version20260820095413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ShareLink Invitations (Epic-01 S3): player_share_link, coach_invitation, trainer_player_association, trainer_coach_association, profile_player.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE player_share_link (id UUID NOT NULL, code VARCHAR(24) NOT NULL, usage_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, trainer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_share_link_code ON player_share_link (code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_player_share_link_trainer ON player_share_link (trainer_id)');
        $this->addSql('ALTER TABLE player_share_link ADD CONSTRAINT FK_D4CE1055FB08EDF6 FOREIGN KEY (trainer_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE coach_invitation (id UUID NOT NULL, invited_email VARCHAR(180) NOT NULL, invited_name VARCHAR(160) DEFAULT NULL, message TEXT DEFAULT NULL, selector VARCHAR(24) NOT NULL, hashed_verifier CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, trainer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9FE01651FB08EDF6 ON coach_invitation (trainer_id)');
        $this->addSql('CREATE INDEX idx_coach_invitation_trainer_created ON coach_invitation (trainer_id, created_at)');
        $this->addSql('CREATE INDEX idx_coach_invitation_email_accepted ON coach_invitation (invited_email, accepted_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_coach_invitation_selector ON coach_invitation (selector)');
        $this->addSql('ALTER TABLE coach_invitation ADD CONSTRAINT FK_9FE01651FB08EDF6 FOREIGN KEY (trainer_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints (same reason S1's
        // app_user_email_lower_ck is hand-written). AC-18's re-invite legality
        // depends on invited_email always being stored normalized.
        $this->addSql("ALTER TABLE coach_invitation ADD CONSTRAINT coach_invitation_email_lower_ck CHECK (invited_email = lower(invited_email))");

        $this->addSql('CREATE TABLE trainer_player_association (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, trainer_id UUID NOT NULL, player_id UUID NOT NULL, share_link_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DF84E466FB08EDF6 ON trainer_player_association (trainer_id)');
        $this->addSql('CREATE INDEX IDX_DF84E46699E6F5DF ON trainer_player_association (player_id)');
        $this->addSql('CREATE INDEX IDX_DF84E466EFC8A8ED ON trainer_player_association (share_link_id)');
        $this->addSql('CREATE INDEX idx_trainer_player_association_player_created ON trainer_player_association (player_id, created_at)');
        $this->addSql('CREATE INDEX idx_trainer_player_association_trainer_created ON trainer_player_association (trainer_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_trainer_player_association ON trainer_player_association (trainer_id, player_id)');
        $this->addSql('ALTER TABLE trainer_player_association ADD CONSTRAINT FK_DF84E466FB08EDF6 FOREIGN KEY (trainer_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trainer_player_association ADD CONSTRAINT FK_DF84E46699E6F5DF FOREIGN KEY (player_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trainer_player_association ADD CONSTRAINT FK_DF84E466EFC8A8ED FOREIGN KEY (share_link_id) REFERENCES player_share_link (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE trainer_coach_association (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, trainer_id UUID NOT NULL, coach_id UUID NOT NULL, invitation_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8529E896FB08EDF6 ON trainer_coach_association (trainer_id)');
        $this->addSql('CREATE INDEX IDX_8529E8963C105691 ON trainer_coach_association (coach_id)');
        $this->addSql('CREATE INDEX IDX_8529E896A35D7AF0 ON trainer_coach_association (invitation_id)');
        $this->addSql('CREATE INDEX idx_trainer_coach_association_trainer_ended ON trainer_coach_association (trainer_id, ended_at)');
        $this->addSql('CREATE INDEX idx_trainer_coach_association_coach_ended ON trainer_coach_association (coach_id, ended_at)');
        // AC-16's exclusivity rule as a partial unique index -- an ended row
        // (ended_at IS NOT NULL) is invisible to it, which is exactly what
        // lets the "ended with Trainer A, accepts Trainer B" edge case
        // succeed. Emitted directly from the ORM mapping (see class docblock).
        $this->addSql('CREATE UNIQUE INDEX uniq_trainer_coach_active_coach ON trainer_coach_association (coach_id) WHERE ended_at IS NULL');
        $this->addSql('ALTER TABLE trainer_coach_association ADD CONSTRAINT FK_8529E896FB08EDF6 FOREIGN KEY (trainer_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trainer_coach_association ADD CONSTRAINT FK_8529E8963C105691 FOREIGN KEY (coach_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE trainer_coach_association ADD CONSTRAINT FK_8529E896A35D7AF0 FOREIGN KEY (invitation_id) REFERENCES coach_invitation (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE profile_player (player_name VARCHAR(160) NOT NULL, declared_age SMALLINT NOT NULL, gender VARCHAR(32) NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE profile_player ADD CONSTRAINT FK_F0331D02BF396750 FOREIGN KEY (id) REFERENCES profile (id) ON DELETE CASCADE');

        // Hand-written: DBAL does not diff CHECK constraints (same reason
        // S1's app_user_role_ck is hand-written). Mirrors PlayerGender's
        // closed domain at the storage level.
        $this->addSql("ALTER TABLE profile_player ADD CONSTRAINT profile_player_gender_ck CHECK (gender IN ('MALE','FEMALE','OTHER','PREFER_NOT_TO_SAY'))");
    }

    /**
     * Reverse of up(), in reverse order. Every table here is new and has no
     * cross-slice dependents, so a plain per-table drop (its own FKs, then
     * the table itself) is sufficient -- unlike S1/S2's app_user CHECK
     * swaps, nothing here needs to be restored to a prior state.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_player DROP CONSTRAINT profile_player_gender_ck');
        $this->addSql('ALTER TABLE profile_player DROP CONSTRAINT FK_F0331D02BF396750');
        $this->addSql('DROP TABLE profile_player');

        $this->addSql('DROP INDEX uniq_trainer_coach_active_coach');
        $this->addSql('ALTER TABLE trainer_coach_association DROP CONSTRAINT FK_8529E896FB08EDF6');
        $this->addSql('ALTER TABLE trainer_coach_association DROP CONSTRAINT FK_8529E8963C105691');
        $this->addSql('ALTER TABLE trainer_coach_association DROP CONSTRAINT FK_8529E896A35D7AF0');
        $this->addSql('DROP TABLE trainer_coach_association');

        $this->addSql('ALTER TABLE trainer_player_association DROP CONSTRAINT FK_DF84E466FB08EDF6');
        $this->addSql('ALTER TABLE trainer_player_association DROP CONSTRAINT FK_DF84E46699E6F5DF');
        $this->addSql('ALTER TABLE trainer_player_association DROP CONSTRAINT FK_DF84E466EFC8A8ED');
        $this->addSql('DROP TABLE trainer_player_association');

        $this->addSql('ALTER TABLE coach_invitation DROP CONSTRAINT coach_invitation_email_lower_ck');
        $this->addSql('ALTER TABLE coach_invitation DROP CONSTRAINT FK_9FE01651FB08EDF6');
        $this->addSql('DROP TABLE coach_invitation');

        $this->addSql('ALTER TABLE player_share_link DROP CONSTRAINT FK_D4CE1055FB08EDF6');
        $this->addSql('DROP TABLE player_share_link');
    }
}
