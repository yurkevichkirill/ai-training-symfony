<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Coach Features (Epic-01, slice S5): profile_coach, coach_availability_slot,
 * coach_assignment_override, and the additive `'COACH'` line in `Profile`'s
 * discriminator map (no DDL of its own).
 *
 * Generated with doctrine:migrations:diff, then reformatted to this
 * project's one-`addSql`-call-per-statement, single-line style and
 * hand-finished for the five CHECK constraints DBAL does not diff:
 * coach_availability_slot's `day_of_week BETWEEN 1 AND 7` plus
 * `starts_at_minute >= 0 AND ends_at_minute <= 1440 AND starts_at_minute <
 * ends_at_minute` (the same pair as S4's player_availability_slot), and
 * coach_assignment_override's identical pair plus `btrim(reason) <> ''`
 * (AC-7's required-reason rule as a database fact, not only a service
 * guard -- D3d).
 *
 * No `ALTER TABLE` anywhere -- every table is new, no existing column
 * changes, and no backfill runs for coaches that already exist (D1c --
 * lazy creation only). `doctrine:schema:update --dump-sql` reports nothing
 * to update on a second run (verified during implementation).
 */
final class Version20260820172355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Coach Features (Epic-01 S5): profile_coach, coach_availability_slot, coach_assignment_override.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE profile_coach (id UUID NOT NULL, bio TEXT DEFAULT NULL, credentials TEXT DEFAULT NULL, certifications TEXT DEFAULT NULL, is_public BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE profile_coach ADD CONSTRAINT FK_C88DF21EBF396750 FOREIGN KEY (id) REFERENCES profile (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE coach_availability_slot (id UUID NOT NULL, day_of_week SMALLINT NOT NULL, starts_at_minute SMALLINT NOT NULL, ends_at_minute SMALLINT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, coach_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_34FDD57F3C105691 ON coach_availability_slot (coach_id)');
        $this->addSql('CREATE INDEX idx_coach_availability_slot_coach_day_start ON coach_availability_slot (coach_id, day_of_week, starts_at_minute)');
        $this->addSql('ALTER TABLE coach_availability_slot ADD CONSTRAINT FK_34FDD57F3C105691 FOREIGN KEY (coach_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints. Same shape as
        // S4's player_availability_slot -- AC-1's ISO-8601 Monday=1...Sunday=7
        // domain and the starts-before-ends, 0...1440 range are both enforced
        // at the storage level, not only in the form.
        $this->addSql("ALTER TABLE coach_availability_slot ADD CONSTRAINT coach_availability_slot_day_ck CHECK (day_of_week BETWEEN 1 AND 7)");
        $this->addSql("ALTER TABLE coach_availability_slot ADD CONSTRAINT coach_availability_slot_range_ck CHECK (starts_at_minute >= 0 AND ends_at_minute <= 1440 AND starts_at_minute < ends_at_minute)");

        $this->addSql('CREATE TABLE coach_assignment_override (id UUID NOT NULL, day_of_week SMALLINT NOT NULL, starts_at_minute SMALLINT NOT NULL, ends_at_minute SMALLINT NOT NULL, coverage VARCHAR(24) NOT NULL, reason TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, coach_id UUID NOT NULL, overridden_by_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_78B820F53C105691 ON coach_assignment_override (coach_id)');
        $this->addSql('CREATE INDEX IDX_78B820F5B30D4288 ON coach_assignment_override (overridden_by_user_id)');
        $this->addSql('CREATE INDEX idx_coach_assignment_override_coach_created ON coach_assignment_override (coach_id, created_at)');
        $this->addSql('CREATE INDEX idx_coach_assignment_override_overridden_by_created ON coach_assignment_override (overridden_by_user_id, created_at)');
        $this->addSql('ALTER TABLE coach_assignment_override ADD CONSTRAINT FK_78B820F53C105691 FOREIGN KEY (coach_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE coach_assignment_override ADD CONSTRAINT FK_78B820F5B30D4288 FOREIGN KEY (overridden_by_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints. Same
        // day_of_week/starts-before-ends pair as coach_availability_slot,
        // plus AC-7's required-reason rule as a database fact (D3d): a row
        // with an empty or whitespace-only reason is unstorable even if some
        // future caller bypasses CoachAssignmentOverrideService::record().
        $this->addSql("ALTER TABLE coach_assignment_override ADD CONSTRAINT coach_assignment_override_day_ck CHECK (day_of_week BETWEEN 1 AND 7)");
        $this->addSql("ALTER TABLE coach_assignment_override ADD CONSTRAINT coach_assignment_override_range_ck CHECK (starts_at_minute >= 0 AND ends_at_minute <= 1440 AND starts_at_minute < ends_at_minute)");
        $this->addSql("ALTER TABLE coach_assignment_override ADD CONSTRAINT coach_assignment_override_reason_ck CHECK (btrim(reason) <> '')");
    }

    /**
     * Reverse of up(), in reverse order. Every table here is new and has no
     * cross-slice dependents, so a plain per-table drop (its CHECK
     * constraints and FKs, then the table itself) is sufficient.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_assignment_override DROP CONSTRAINT coach_assignment_override_reason_ck');
        $this->addSql('ALTER TABLE coach_assignment_override DROP CONSTRAINT coach_assignment_override_range_ck');
        $this->addSql('ALTER TABLE coach_assignment_override DROP CONSTRAINT coach_assignment_override_day_ck');
        $this->addSql('ALTER TABLE coach_assignment_override DROP CONSTRAINT FK_78B820F53C105691');
        $this->addSql('ALTER TABLE coach_assignment_override DROP CONSTRAINT FK_78B820F5B30D4288');
        $this->addSql('DROP TABLE coach_assignment_override');

        $this->addSql('ALTER TABLE coach_availability_slot DROP CONSTRAINT coach_availability_slot_range_ck');
        $this->addSql('ALTER TABLE coach_availability_slot DROP CONSTRAINT coach_availability_slot_day_ck');
        $this->addSql('ALTER TABLE coach_availability_slot DROP CONSTRAINT FK_34FDD57F3C105691');
        $this->addSql('DROP TABLE coach_availability_slot');

        $this->addSql('ALTER TABLE profile_coach DROP CONSTRAINT FK_C88DF21EBF396750');
        $this->addSql('DROP TABLE profile_coach');
    }
}
