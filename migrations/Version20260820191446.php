<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trainer Portal Branding (Epic-01, slice S7): two additive nullable
 * columns on the existing `profile_trainer` table, `logo_key` and
 * `primary_color_hex`. No new table, no new entity, no backfill -- an
 * existing trainer row is correctly represented by two `NULL`s (D1, D1b).
 *
 * Generated with doctrine:migrations:diff, then hand-finished for the one
 * CHECK constraint DBAL does not diff (same reason S1's
 * app_user_email_lower_ck and S4's several CHECKs are hand-written):
 * `primary_color_hex` must be `NULL` or a lowercase `#rrggbb` hex string.
 * This is the third layer of AC-9's "only a valid hex is saved" invariant,
 * alongside `TrainerBrandingRequest`'s normalisation and
 * `TrainerBrandingFormType`'s `Regex` constraint.
 */
final class Version20260820191446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trainer Portal Branding (Epic-01 S7): profile_trainer.logo_key, profile_trainer.primary_color_hex.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_trainer ADD logo_key VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE profile_trainer ADD primary_color_hex VARCHAR(7) DEFAULT NULL');

        // Hand-written: DBAL does not diff CHECK constraints. Enforces the
        // hex-color shape at the storage level, not only in the form/DTO.
        $this->addSql("ALTER TABLE profile_trainer ADD CONSTRAINT profile_trainer_primary_color_hex_ck CHECK (primary_color_hex IS NULL OR primary_color_hex ~ '^#[0-9a-f]{6}$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_trainer DROP CONSTRAINT profile_trainer_primary_color_hex_ck');
        $this->addSql('ALTER TABLE profile_trainer DROP logo_key');
        $this->addSql('ALTER TABLE profile_trainer DROP primary_color_hex');
    }
}
