<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auth foundation (Epic-01, slice S1): app_user, email_verification_token,
 * reset_password_request and auth_event.
 *
 * Generated with doctrine:migrations:diff, then hand-finished to add the three
 * CHECK constraints on app_user. Doctrine DBAL does not model check
 * constraints, so they must be written by hand -- and because DBAL cannot see
 * them, they also produce no schema-diff noise afterwards.
 *
 * This migration creates no account. There is deliberately no INSERT anywhere
 * in it (AC-25): the first Super Admin is created by the app:create-super-admin
 * console command, never by migration history, so no environment is ever
 * provisioned with a known credential.
 */
final class Version20260818151509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Auth foundation (Epic-01 S1): app_user, email_verification_token, reset_password_request, auth_event.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, password_changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');

        // Hand-written: not expressible through Doctrine mapping.
        //
        // The lower() check is what makes uniq_app_user_email a case-insensitive
        // index by construction -- an unnormalized address is simply unstorable,
        // so no future code path can defeat User::normalizeEmail() (AC-5).
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT app_user_email_lower_ck CHECK (email = lower(email))");
        // One role per user is a product rule, not just a PHP enum: the column
        // is scalar and its domain is closed here too, so a second role stays
        // unrepresentable even for code that bypasses the entity (AC-15).
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT app_user_role_ck CHECK (role IN ('ROLE_SUPER_ADMIN','ROLE_TRAINER','ROLE_COACH','ROLE_PLAYER'))");
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT app_user_status_ck CHECK (status IN ('ACTIVE','DEACTIVATED'))");

        $this->addSql('CREATE TABLE auth_event (id UUID NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, type VARCHAR(64) NOT NULL, outcome VARCHAR(16) NOT NULL, identifier_attempted VARCHAR(180) DEFAULT NULL, ip INET DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, context JSONB NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F78C5BD2A76ED395 ON auth_event (user_id)');
        $this->addSql('CREATE INDEX idx_auth_event_user_occurred ON auth_event (user_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_auth_event_type_occurred ON auth_event (type, occurred_at)');
        $this->addSql('CREATE INDEX idx_auth_event_ip_occurred ON auth_event (ip, occurred_at)');

        $this->addSql('CREATE TABLE email_verification_token (id UUID NOT NULL, selector VARCHAR(24) NOT NULL, hashed_verifier CHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C4995C67A76ED395 ON email_verification_token (user_id)');
        $this->addSql('CREATE INDEX idx_email_verification_token_user_consumed ON email_verification_token (user_id, consumed_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_verification_token_selector ON email_verification_token (selector)');

        $this->addSql('CREATE TABLE reset_password_request (id UUID NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_reset_password_request_user ON reset_password_request (user_id)');

        $this->addSql('ALTER TABLE auth_event ADD CONSTRAINT FK_F78C5BD2A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_verification_token ADD CONSTRAINT FK_C4995C67A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    /**
     * The exact reverse of up(), in reverse order: foreign keys, then the
     * dependent tables, then the CHECK constraints, then app_user last --
     * nothing references a table that still exists at the point it is dropped.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reset_password_request DROP CONSTRAINT FK_7CE748AA76ED395');
        $this->addSql('ALTER TABLE email_verification_token DROP CONSTRAINT FK_C4995C67A76ED395');
        $this->addSql('ALTER TABLE auth_event DROP CONSTRAINT FK_F78C5BD2A76ED395');

        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('DROP TABLE email_verification_token');
        $this->addSql('DROP TABLE auth_event');

        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_status_ck');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_role_ck');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT app_user_email_lower_ck');

        $this->addSql('DROP TABLE app_user');
    }
}
