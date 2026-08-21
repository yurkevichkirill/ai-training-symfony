<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Player/Family Availability (Epic-01, slice S4): child_account,
 * child_trainer_request, player_availability_slot, and one additive column
 * on profile_player (school).
 *
 * Generated with doctrine:migrations:diff, then reformatted to this
 * project's one-`addSql`-call-per-statement, single-line style (matching
 * every other migration in this project) and hand-finished for the four
 * CHECK constraints DBAL does not diff: child_account's
 * `child_user_id <> parent_user_id` (an account cannot parent itself),
 * child_trainer_request's `(resolved_at IS NULL) = (resolution IS NULL)`
 * (no half-resolved row), and player_availability_slot's `day_of_week
 * BETWEEN 1 AND 7` plus `starts_at_minute >= 0 AND ends_at_minute <= 1440
 * AND starts_at_minute < ends_at_minute`.
 *
 * The partial unique index `uniq_child_trainer_request_pending
 * (child_user_id, trainer_id) WHERE (resolved_at IS NULL)` needed no
 * hand-written line -- the diff emitted it correctly, pre-parenthesized,
 * straight from `ChildTrainerRequest`'s
 * `#[ORM\UniqueConstraint(..., options: ['where' => '(resolved_at IS NULL)'])]`,
 * the same technique already proven not to perpetually diff on
 * `trainer_coach_association.uniq_trainer_coach_active_coach`
 * (Version20260820095413) and `trainer_player_association.uniq_trainer_player_active_association`
 * (Version20260820131012) -- confirmed again here by running
 * `doctrine:schema:update --dump-sql` twice after this migration and getting
 * "Nothing to update" both times.
 */
final class Version20260820143957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player/Family Availability (Epic-01 S4): child_account, child_trainer_request, player_availability_slot, profile_player.school.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE child_account (id UUID NOT NULL, sign_in_enabled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, child_user_id UUID NOT NULL, parent_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1F821EBFD526A7D3 ON child_account (parent_user_id)');
        $this->addSql('CREATE INDEX idx_child_account_parent_created ON child_account (parent_user_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_child_account_child_user ON child_account (child_user_id)');
        $this->addSql('ALTER TABLE child_account ADD CONSTRAINT FK_1F821EBFC5DA9B8E FOREIGN KEY (child_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE child_account ADD CONSTRAINT FK_1F821EBFD526A7D3 FOREIGN KEY (parent_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints (same reason
        // S1's app_user_email_lower_ck is hand-written). An account can never
        // parent itself.
        $this->addSql("ALTER TABLE child_account ADD CONSTRAINT child_account_not_self_ck CHECK (child_user_id <> parent_user_id)");

        $this->addSql('CREATE TABLE child_trainer_request (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_notified_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, resolution VARCHAR(16) DEFAULT NULL, child_user_id UUID NOT NULL, trainer_id UUID NOT NULL, parent_user_id UUID NOT NULL, share_link_id UUID DEFAULT NULL, resolved_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C334D57DC5DA9B8E ON child_trainer_request (child_user_id)');
        $this->addSql('CREATE INDEX IDX_C334D57DFB08EDF6 ON child_trainer_request (trainer_id)');
        $this->addSql('CREATE INDEX IDX_C334D57DD526A7D3 ON child_trainer_request (parent_user_id)');
        $this->addSql('CREATE INDEX IDX_C334D57DEFC8A8ED ON child_trainer_request (share_link_id)');
        $this->addSql('CREATE INDEX IDX_C334D57DAC78F73B ON child_trainer_request (resolved_by_user_id)');
        $this->addSql('CREATE INDEX idx_child_trainer_request_parent_resolved_created ON child_trainer_request (parent_user_id, resolved_at, created_at)');
        // AC-15/AC-16's exclusivity rule as a partial unique index -- a
        // resolved row (resolved_at IS NOT NULL) is invisible to it, which is
        // what admits a fresh request once a prior one for the same (child,
        // trainer) pair has been resolved. Emitted directly from the ORM
        // mapping (see class docblock).
        $this->addSql('CREATE UNIQUE INDEX uniq_child_trainer_request_pending ON child_trainer_request (child_user_id, trainer_id) WHERE (resolved_at IS NULL)');
        $this->addSql('ALTER TABLE child_trainer_request ADD CONSTRAINT FK_C334D57DC5DA9B8E FOREIGN KEY (child_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE child_trainer_request ADD CONSTRAINT FK_C334D57DFB08EDF6 FOREIGN KEY (trainer_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE child_trainer_request ADD CONSTRAINT FK_C334D57DD526A7D3 FOREIGN KEY (parent_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE child_trainer_request ADD CONSTRAINT FK_C334D57DEFC8A8ED FOREIGN KEY (share_link_id) REFERENCES player_share_link (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE child_trainer_request ADD CONSTRAINT FK_C334D57DAC78F73B FOREIGN KEY (resolved_by_user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints. A half-resolved
        // row (only one of resolved_at/resolution set) is unstorable.
        $this->addSql("ALTER TABLE child_trainer_request ADD CONSTRAINT child_trainer_request_resolution_ck CHECK ((resolved_at IS NULL) = (resolution IS NULL))");

        $this->addSql('CREATE TABLE player_availability_slot (id UUID NOT NULL, day_of_week SMALLINT NOT NULL, starts_at_minute SMALLINT NOT NULL, ends_at_minute SMALLINT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7B2D41D099E6F5DF ON player_availability_slot (player_id)');
        $this->addSql('CREATE INDEX idx_player_availability_slot_player_day_start ON player_availability_slot (player_id, day_of_week, starts_at_minute)');
        $this->addSql('CREATE INDEX idx_player_availability_slot_day_start_end ON player_availability_slot (day_of_week, starts_at_minute, ends_at_minute)');
        $this->addSql('ALTER TABLE player_availability_slot ADD CONSTRAINT FK_7B2D41D099E6F5DF FOREIGN KEY (player_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints. AC-24's ISO-8601
        // Monday=1...Sunday=7 domain and the starts-before-ends, 0...1440
        // range are both enforced at the storage level, not only in the form.
        $this->addSql("ALTER TABLE player_availability_slot ADD CONSTRAINT player_availability_slot_day_ck CHECK (day_of_week BETWEEN 1 AND 7)");
        $this->addSql("ALTER TABLE player_availability_slot ADD CONSTRAINT player_availability_slot_range_ck CHECK (starts_at_minute >= 0 AND ends_at_minute <= 1440 AND starts_at_minute < ends_at_minute)");

        $this->addSql('ALTER TABLE profile_player ADD school VARCHAR(160) DEFAULT NULL');
    }

    /**
     * Reverse of up(), in reverse order. Every table here is new and has no
     * cross-slice dependents, so a plain per-table drop (its CHECK
     * constraints and FKs, then the table itself) is sufficient; the one
     * existing table touched, profile_player, only loses its new nullable
     * column.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_player DROP school');

        $this->addSql('ALTER TABLE player_availability_slot DROP CONSTRAINT player_availability_slot_range_ck');
        $this->addSql('ALTER TABLE player_availability_slot DROP CONSTRAINT player_availability_slot_day_ck');
        $this->addSql('ALTER TABLE player_availability_slot DROP CONSTRAINT FK_7B2D41D099E6F5DF');
        $this->addSql('DROP TABLE player_availability_slot');

        $this->addSql('ALTER TABLE child_trainer_request DROP CONSTRAINT child_trainer_request_resolution_ck');
        $this->addSql('ALTER TABLE child_trainer_request DROP CONSTRAINT FK_C334D57DC5DA9B8E');
        $this->addSql('ALTER TABLE child_trainer_request DROP CONSTRAINT FK_C334D57DFB08EDF6');
        $this->addSql('ALTER TABLE child_trainer_request DROP CONSTRAINT FK_C334D57DD526A7D3');
        $this->addSql('ALTER TABLE child_trainer_request DROP CONSTRAINT FK_C334D57DEFC8A8ED');
        $this->addSql('ALTER TABLE child_trainer_request DROP CONSTRAINT FK_C334D57DAC78F73B');
        $this->addSql('DROP TABLE child_trainer_request');

        $this->addSql('ALTER TABLE child_account DROP CONSTRAINT child_account_not_self_ck');
        $this->addSql('ALTER TABLE child_account DROP CONSTRAINT FK_1F821EBFC5DA9B8E');
        $this->addSql('ALTER TABLE child_account DROP CONSTRAINT FK_1F821EBFD526A7D3');
        $this->addSql('DROP TABLE child_account');
    }
}
