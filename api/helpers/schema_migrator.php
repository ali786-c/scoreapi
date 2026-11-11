<?php
// 60Score / STUMPS Backend - Idempotent Schema Migrator (v3 Full App Coverage)
// Safely upgrades existing cPanel MySQL databases without touching live data:
// Adds Google Auth columns, full Drafts/RegPlayers/ManualStats/Tombstones tables,
// and missing squad/match telemetry columns.

class SchemaMigrator {
    private static bool $checkedThisRequest = false;

    public static function ensureGoogleAuthColumns(PDO $pdo): void {
        self::ensureSchemaUpToDate($pdo);
    }

    public static function ensureSchemaUpToDate(PDO $pdo): void {
        if (self::$checkedThisRequest) {
            return;
        }
        self::$checkedThisRequest = true;

        $lockPath = __DIR__ . '/../config/schema_v3.lock';
        if (file_exists($lockPath)) {
            return;
        }

        try {
            $getCols = function (string $table) use ($pdo): array {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                $cols = [];
                foreach ($stmt->fetchAll() as $row) {
                    $cols[$row['Field']] = $row;
                }
                return $cols;
            };

            // 1. users
            $userCols = $getCols('users');
            if (!isset($userCols['google_id'])) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(128) NULL UNIQUE AFTER `email`");
            }
            if (!isset($userCols['auth_provider'])) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `auth_provider` ENUM('google', 'email') NOT NULL DEFAULT 'email' AFTER `google_id`");
            }
            if (isset($userCols['password_hash']) && strtoupper($userCols['password_hash']['Null'] ?? 'NO') === 'NO') {
                $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL");
            }
            if (!isset($userCols['profile_completed'])) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `profile_completed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`");
            }

            // 2. players
            $playerCols = $getCols('players');
            if (!isset($playerCols['jersey_number'])) {
                $pdo->exec("ALTER TABLE `players` ADD COLUMN `jersey_number` INT NOT NULL DEFAULT 7 AFTER `bowling_style`");
            }
            if (!isset($playerCols['owner_uid'])) {
                $pdo->exec("ALTER TABLE `players` ADD COLUMN `owner_uid` VARCHAR(64) NULL AFTER `linked_user_id`");
            }
            if (!isset($playerCols['updated_at_epoch'])) {
                $pdo->exec("ALTER TABLE `players` ADD COLUMN `updated_at_epoch` BIGINT NOT NULL DEFAULT 0 AFTER `career_stats_json`");
            }

            // 3. teams
            $teamCols = $getCols('teams');
            if (!isset($teamCols['team_payload'])) {
                $pdo->exec("ALTER TABLE `teams` ADD COLUMN `team_payload` LONGTEXT NULL AFTER `creator_id`");
            }
            if (!isset($teamCols['updated_at_epoch'])) {
                $pdo->exec("ALTER TABLE `teams` ADD COLUMN `updated_at_epoch` BIGINT NOT NULL DEFAULT 0 AFTER `team_payload`");
            }

            // 4. team_players
            $tpCols = $getCols('team_players');
            if (!isset($tpCols['role'])) {
                $pdo->exec("ALTER TABLE `team_players` ADD COLUMN `role` VARCHAR(50) DEFAULT 'Batter' AFTER `jersey_no`");
            }
            if (!isset($tpCols['is_captain'])) {
                $pdo->exec("ALTER TABLE `team_players` ADD COLUMN `is_captain` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`");
            }
            if (!isset($tpCols['is_vice_captain'])) {
                $pdo->exec("ALTER TABLE `team_players` ADD COLUMN `is_vice_captain` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_captain`");
            }
            if (!isset($tpCols['is_keeper'])) {
                $pdo->exec("ALTER TABLE `team_players` ADD COLUMN `is_keeper` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_vice_captain`");
            }

            // 5. matches
            $matchCols = $getCols('matches');
            if (!isset($matchCols['total_wickets'])) {
                $pdo->exec("ALTER TABLE `matches` ADD COLUMN `total_wickets` INT NOT NULL DEFAULT 10 AFTER `balls_per_over`");
            }
            if (!isset($matchCols['last_man_stands'])) {
                $pdo->exec("ALTER TABLE `matches` ADD COLUMN `last_man_stands` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total_wickets`");
            }
            if (!isset($matchCols['wide_penalty'])) {
                $pdo->exec("ALTER TABLE `matches` ADD COLUMN `wide_penalty` TINYINT(1) NOT NULL DEFAULT 1 AFTER `last_man_stands`");
            }
            if (!isset($matchCols['no_ball_penalty'])) {
                $pdo->exec("ALTER TABLE `matches` ADD COLUMN `no_ball_penalty` TINYINT(1) NOT NULL DEFAULT 1 AFTER `wide_penalty`");
            }

            // 6. tournaments
            $tournCols = $getCols('tournaments');
            if (!isset($tournCols['overs_limit'])) {
                $pdo->exec("ALTER TABLE `tournaments` ADD COLUMN `overs_limit` INT NOT NULL DEFAULT 8 AFTER `format`");
            }

            // 7. drafts
            $draftCols = $getCols('drafts');
            if (!isset($draftCols['name'])) {
                $pdo->exec("ALTER TABLE `drafts` ADD COLUMN `name` VARCHAR(150) DEFAULT '' AFTER `owner_uid`");
            }
            if (!isset($draftCols['status'])) {
                $pdo->exec("ALTER TABLE `drafts` ADD COLUMN `status` VARCHAR(32) DEFAULT 'active' AFTER `name`");
            }
            if (!isset($draftCols['applied_to_teams'])) {
                $pdo->exec("ALTER TABLE `drafts` ADD COLUMN `applied_to_teams` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
            }
            if (!isset($draftCols['updated_at_epoch'])) {
                $pdo->exec("ALTER TABLE `drafts` ADD COLUMN `updated_at_epoch` BIGINT NOT NULL DEFAULT 0 AFTER `draft_payload`");
            }

            // 8. reg_players (Registration / Draft Pool)
            $pdo->exec("CREATE TABLE IF NOT EXISTS `reg_players` (
              `id` VARCHAR(64) PRIMARY KEY,
              `owner_uid` VARCHAR(64) NOT NULL,
              `name` VARCHAR(100) NOT NULL,
              `role` VARCHAR(50) DEFAULT 'Batter',
              `availability` VARCHAR(32) DEFAULT 'Available',
              `area` VARCHAR(100) DEFAULT '',
              `phone` VARCHAR(50) DEFAULT '',
              `remarks` VARCHAR(255) DEFAULT '',
              `batting_style` VARCHAR(50) DEFAULT 'Right-hand',
              `bowling_style` VARCHAR(50) DEFAULT 'None',
              `age` INT NOT NULL DEFAULT 0,
              `category` VARCHAR(64) DEFAULT '',
              `reg_status` VARCHAR(32) DEFAULT 'Confirmed',
              `team_name` VARCHAR(100) DEFAULT '',
              `jersey_no` INT NOT NULL DEFAULT 0,
              `is_captain` TINYINT(1) NOT NULL DEFAULT 0,
              `is_keeper` TINYINT(1) NOT NULL DEFAULT 0,
              `photo_base64` MEDIUMTEXT DEFAULT NULL,
              `reg_payload` LONGTEXT NULL,
              `updated_at_epoch` BIGINT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_reg_owner` (`owner_uid`),
              INDEX `idx_reg_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // 9. manual_stats (Manual Career Past Performances)
            $pdo->exec("CREATE TABLE IF NOT EXISTS `manual_stats` (
              `id` VARCHAR(64) PRIMARY KEY,
              `owner_uid` VARCHAR(64) NOT NULL,
              `player_id` VARCHAR(64) NOT NULL,
              `player_name` VARCHAR(100) DEFAULT '',
              `match_label` VARCHAR(150) DEFAULT '',
              `date_iso` VARCHAR(50) DEFAULT '',
              `runs` INT NOT NULL DEFAULT 0,
              `balls` INT NOT NULL DEFAULT 0,
              `fours` INT NOT NULL DEFAULT 0,
              `sixes` INT NOT NULL DEFAULT 0,
              `not_out` TINYINT(1) NOT NULL DEFAULT 0,
              `wickets` INT NOT NULL DEFAULT 0,
              `runs_conceded` INT NOT NULL DEFAULT 0,
              `balls_bowled` INT NOT NULL DEFAULT 0,
              `catches` INT NOT NULL DEFAULT 0,
              `stumpings` INT NOT NULL DEFAULT 0,
              `updated_at_epoch` BIGINT NOT NULL DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_manual_owner` (`owner_uid`),
              INDEX `idx_manual_player` (`player_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // 10. tombstones (Deletion Sync Across Devices)
            $pdo->exec("CREATE TABLE IF NOT EXISTS `tombstones` (
              `doc_id` VARCHAR(140) PRIMARY KEY,
              `entity_kind` VARCHAR(32) NOT NULL,
              `entity_id` VARCHAR(64) NOT NULL,
              `owner_uid` VARCHAR(64) NOT NULL,
              `deleted_at_epoch` BIGINT NOT NULL,
              `device_id` VARCHAR(128) DEFAULT '',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_tomb_owner` (`owner_uid`),
              INDEX `idx_tomb_kind` (`entity_kind`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            @file_put_contents($lockPath, 'Migrated to v3 (Full 10-table coverage) at ' . date('c'));
        } catch (Throwable $e) {
            error_log('[SchemaMigrator] Migration warning: ' . $e->getMessage());
        }
    }
}
