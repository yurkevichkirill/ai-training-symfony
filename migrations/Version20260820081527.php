<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User management basics (Epic-01, slice S2): app_user gains common display
 * columns and a DELETED status; the frozen Profile/ProfileTrainer hierarchy;
 * account_invitation, account_event, account_deletion_log.
 *
 * Generated with doctrine:migrations:diff, then hand-finished for the parts
 * Doctrine DBAL cannot diff: the app_user status CHECK constraint (rewritten
 * to include DELETED), the (status, role, created_at) directory index, and
 * the pg_trgm extension + trigram index the Users-tool business-name search
 * needs.
 */
final class Version20260820081527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User management basics (Epic-01 S2): profile hierarchy, account_invitation, account_event, account_deletion_log, app_user common fields + DELETED status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD first_name VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD last_name VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD phone VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD photo_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_app_user_status_role_created ON app_user (status, role, created_at)');

        // Hand-written: DBAL does not diff CHECK constraints (same reason S1's
        // three app_user checks are hand-written). Widening the domain to
        // include DELETED, not adding a second constraint.
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_status_ck');
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT app_user_status_ck CHECK (status IN ('ACTIVE','DEACTIVATED','DELETED'))");

        $this->addSql('CREATE TABLE profile (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, type VARCHAR(32) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8157AA0FA76ED395 ON profile (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_profile_user_type ON profile (user_id, type)');
        $this->addSql('ALTER TABLE profile ADD CONSTRAINT FK_8157AA0FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE profile_trainer (id UUID NOT NULL, business_name VARCHAR(160) NOT NULL, website VARCHAR(255) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, description TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE profile_trainer ADD CONSTRAINT FK_16ABD6BCBF396750 FOREIGN KEY (id) REFERENCES profile (id) ON DELETE CASCADE');

        // pg_trgm: the Users-tool search matches trainer business names by
        // substring (AC-2); a plain B-tree index cannot support that, and the
        // data volume (NFR-002) does not justify a separate search service.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX idx_profile_trainer_business_name_trgm ON profile_trainer USING gin (lower(business_name) gin_trgm_ops)');

        $this->addSql('CREATE TABLE account_invitation (id UUID NOT NULL, selector VARCHAR(24) NOT NULL, hashed_verifier CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, issued_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D869306A76ED395 ON account_invitation (user_id)');
        $this->addSql('CREATE INDEX IDX_D869306B82713E2 ON account_invitation (issued_by_user_id)');
        $this->addSql('CREATE INDEX idx_account_invitation_user_consumed ON account_invitation (user_id, consumed_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_account_invitation_selector ON account_invitation (selector)');
        $this->addSql('ALTER TABLE account_invitation ADD CONSTRAINT FK_D869306A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE account_invitation ADD CONSTRAINT FK_D869306B82713E2 FOREIGN KEY (issued_by_user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('CREATE TABLE account_event (id UUID NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, type VARCHAR(64) NOT NULL, ip INET DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, context JSONB NOT NULL, actor_user_id UUID DEFAULT NULL, subject_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_account_event_subject_occurred ON account_event (subject_user_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_account_event_actor_occurred ON account_event (actor_user_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_account_event_type_occurred ON account_event (type, occurred_at)');
        $this->addSql('ALTER TABLE account_event ADD CONSTRAINT FK_D21D3D6859B83FF FOREIGN KEY (actor_user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE account_event ADD CONSTRAINT FK_D21D3D62EC7F37 FOREIGN KEY (subject_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE account_deletion_log (id UUID NOT NULL, anonymized_email VARCHAR(180) NOT NULL, reference VARCHAR(120) DEFAULT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, subject_user_id UUID NOT NULL, actor_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_account_deletion_log_subject ON account_deletion_log (subject_user_id)');
        $this->addSql('CREATE INDEX IDX_3F71D1ED859B83FF ON account_deletion_log (actor_user_id)');
        $this->addSql('ALTER TABLE account_deletion_log ADD CONSTRAINT FK_3F71D1ED2EC7F37 FOREIGN KEY (subject_user_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE account_deletion_log ADD CONSTRAINT FK_3F71D1ED859B83FF FOREIGN KEY (actor_user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    /**
     * Reverse of up(), in reverse order. `pg_trgm` itself is left installed
     * (other objects may come to depend on it, and DROP EXTENSION would fail
     * once they do); only the index this migration created is dropped.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_deletion_log DROP CONSTRAINT FK_3F71D1ED2EC7F37');
        $this->addSql('ALTER TABLE account_deletion_log DROP CONSTRAINT FK_3F71D1ED859B83FF');
        $this->addSql('DROP TABLE account_deletion_log');

        $this->addSql('ALTER TABLE account_event DROP CONSTRAINT FK_D21D3D6859B83FF');
        $this->addSql('ALTER TABLE account_event DROP CONSTRAINT FK_D21D3D62EC7F37');
        $this->addSql('DROP TABLE account_event');

        $this->addSql('ALTER TABLE account_invitation DROP CONSTRAINT FK_D869306A76ED395');
        $this->addSql('ALTER TABLE account_invitation DROP CONSTRAINT FK_D869306B82713E2');
        $this->addSql('DROP TABLE account_invitation');

        $this->addSql('DROP INDEX idx_profile_trainer_business_name_trgm');
        $this->addSql('ALTER TABLE profile_trainer DROP CONSTRAINT FK_16ABD6BCBF396750');
        $this->addSql('DROP TABLE profile_trainer');

        $this->addSql('ALTER TABLE profile DROP CONSTRAINT FK_8157AA0FA76ED395');
        $this->addSql('DROP TABLE profile');

        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_status_ck');
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT app_user_status_ck CHECK (status IN ('ACTIVE','DEACTIVATED'))");
        $this->addSql('DROP INDEX idx_app_user_status_role_created');
        $this->addSql('ALTER TABLE app_user DROP first_name');
        $this->addSql('ALTER TABLE app_user DROP last_name');
        $this->addSql('ALTER TABLE app_user DROP phone');
        $this->addSql('ALTER TABLE app_user DROP photo_key');
    }
}
