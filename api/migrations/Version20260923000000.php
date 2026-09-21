<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deterministic, idempotent backfill of alert_evaluation_progress
 * (PR #10 review follow-up to Version20260922000000).
 *
 * Version20260922000000's original backfill joined on MAX(last_considered_
 * measurement_id) per (alert_type, subject_key) with no tie-breaker: two
 * alert_incident rows sharing the same key and the same max measurement id
 * (possible from historical replay bugs) made that INSERT emit two rows for
 * one target unique key, aborting the migration. This version picks exactly
 * one source row per key via ROW_NUMBER(), ordered by the highest
 * measurement id, then the highest incident id as the deterministic
 * tie-breaker, so (measurement_id, measured_at) always comes from the same
 * row and stays coherent.
 *
 * The upsert never regresses progress a running application has already
 * advanced past the historical backfill value (e.g. this migration runs
 * against an installation where Version20260922000000 already shipped
 * without its backfill, and live processing has since moved progress
 * forward): each column is only overwritten when the candidate's
 * measurement id is strictly higher than what is already stored.
 */
final class Version20260923000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill alert_evaluation_progress with a deterministic tie-breaker, never regressing live-advanced progress';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO alert_evaluation_progress AS target
                (alert_type, subject_key, last_considered_measurement_id, last_considered_measured_at, created_at, updated_at)
            SELECT ranked.alert_type, ranked.subject_key, ranked.last_considered_measurement_id, ranked.last_measured_at, NOW(), NOW()
            FROM (
                SELECT alert_type, subject_key, last_considered_measurement_id, last_measured_at,
                       ROW_NUMBER() OVER (PARTITION BY alert_type, subject_key
                                           ORDER BY last_considered_measurement_id DESC, id DESC) AS rn
                FROM alert_incident
                WHERE last_considered_measurement_id IS NOT NULL
            ) ranked
            WHERE ranked.rn = 1
            ON DUPLICATE KEY UPDATE
                last_considered_measurement_id = CASE
                    WHEN target.last_considered_measurement_id IS NULL OR VALUES(last_considered_measurement_id) > target.last_considered_measurement_id
                    THEN VALUES(last_considered_measurement_id) ELSE target.last_considered_measurement_id END,
                last_considered_measured_at = CASE
                    WHEN target.last_considered_measurement_id IS NULL OR VALUES(last_considered_measurement_id) > target.last_considered_measurement_id
                    THEN VALUES(last_considered_measured_at) ELSE target.last_considered_measured_at END,
                updated_at = CASE
                    WHEN target.last_considered_measurement_id IS NULL OR VALUES(last_considered_measurement_id) > target.last_considered_measurement_id
                    THEN VALUES(updated_at) ELSE target.updated_at END
            SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: the backfilled rows' table is dropped by Version20260922000000's own down().
    }
}
