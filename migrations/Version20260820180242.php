<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Super Admin Impersonation and Audit (Epic-01, slice S6): one new table,
 * `impersonation_session`. Generated with `doctrine:migrations:diff`, then
 * hand-finished for the three CHECK constraints and the partial unique
 * index DBAL does not diff (same reason as S3/S4/S5's hand-written lines):
 *
 * - `impersonation_session_end_pair_ck`: BR-003's "closed exactly once,
 *   with a reason" as a database fact -- a half-closed row (an end time
 *   with no reason, or vice versa) is unrepresentable.
 * - `impersonation_session_expires_after_started_ck`,
 *   `impersonation_session_ended_not_before_started_ck`: the two ordering
 *   invariants on the three timestamps.
 * - `uniq_impersonation_active_actor`, pre-parenthesized
 *   (`WHERE (ended_at IS NULL)`) for `pg_get_expr` canonical-form
 *   stability, S3/S4's proven technique: at most one open session per
 *   actor, which is what makes the nested-impersonation edge case a
 *   database refusal rather than an app-level check, and "find the open
 *   session for this actor" a single indexed row (NFR-001).
 *
 * No `ALTER TABLE`, no backfill -- the table is new and `AccountEventType`'s
 * two new cases (Task 4) are PHP-only (`account_event.type` is
 * `varchar(64)`).
 */
final class Version20260820180242 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Super Admin Impersonation and Audit (Epic-01 S6): impersonation_session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE impersonation_session (id UUID NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, end_reason VARCHAR(24) DEFAULT NULL, actor_ip INET DEFAULT NULL, actor_user_id UUID NOT NULL, subject_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C7AA2315859B83FF ON impersonation_session (actor_user_id)');
        $this->addSql('CREATE INDEX IDX_C7AA23152EC7F37 ON impersonation_session (subject_user_id)');
        $this->addSql('CREATE INDEX idx_impersonation_session_actor_started ON impersonation_session (actor_user_id, started_at)');
        $this->addSql('CREATE INDEX idx_impersonation_session_subject_started ON impersonation_session (subject_user_id, started_at)');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT FK_C7AA2315859B83FF FOREIGN KEY (actor_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT FK_C7AA23152EC7F37 FOREIGN KEY (subject_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');

        // Hand-written: DBAL does not diff CHECK constraints or partial
        // indexes (same reason as S3/S4/S5's equivalents). BR-003's "closed
        // exactly once, with a reason" as a database fact.
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT impersonation_session_end_pair_ck CHECK ((ended_at IS NULL AND end_reason IS NULL) OR (ended_at IS NOT NULL AND end_reason IS NOT NULL))');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT impersonation_session_expires_after_started_ck CHECK (expires_at > started_at)');
        $this->addSql('ALTER TABLE impersonation_session ADD CONSTRAINT impersonation_session_ended_not_before_started_ck CHECK (ended_at IS NULL OR ended_at >= started_at)');
        // Pre-parenthesized WHERE clause for pg_get_expr canonical-form
        // stability (S3/S4's proven technique) -- at most one open session
        // per actor.
        $this->addSql('CREATE UNIQUE INDEX uniq_impersonation_active_actor ON impersonation_session (actor_user_id) WHERE (ended_at IS NULL)');
    }

    /**
     * Reverse of up(), in reverse order. The table is new and has no
     * cross-slice dependents, so a plain drop is sufficient.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_impersonation_active_actor');
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT impersonation_session_ended_not_before_started_ck');
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT impersonation_session_expires_after_started_ck');
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT impersonation_session_end_pair_ck');
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT FK_C7AA2315859B83FF');
        $this->addSql('ALTER TABLE impersonation_session DROP CONSTRAINT FK_C7AA23152EC7F37');
        $this->addSql('DROP TABLE impersonation_session');
    }
}
