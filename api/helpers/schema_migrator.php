<?php
// 60Score / STUMPS Backend - Idempotent Schema Migrator
// Automatically upgrades existing cPanel MySQL databases to support Google Sign-In
// and First-Time Profile Onboarding without touching existing matches or admin users.

class SchemaMigrator {
    public static function ensureGoogleAuthColumns(PDO $pdo): void {
        $lockPath = __DIR__ . '/../config/schema_v2.lock';
        if (file_exists($lockPath)) {
            return;
        }

        try {
            // 1. Check users table columns
            $colsStmt = $pdo->query("SHOW COLUMNS FROM `users`");
            $userCols = [];
            foreach ($colsStmt->fetchAll() as $row) {
                $userCols[$row['Field']] = $row;
            }

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
                // Existing accounts default to 1 so returning/admin users skip onboarding
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `profile_completed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`");
            }

            // 2. Check players table columns
            $pColsStmt = $pdo->query("SHOW COLUMNS FROM `players`");
            $playerCols = [];
            foreach ($pColsStmt->fetchAll() as $row) {
                $playerCols[$row['Field']] = $row;
            }
            if (!isset($playerCols['jersey_number'])) {
                $pdo->exec("ALTER TABLE `players` ADD COLUMN `jersey_number` INT NOT NULL DEFAULT 7 AFTER `bowling_style`");
            }

            @file_put_contents($lockPath, 'Migrated to v2 (Google Auth + Onboarding) at ' . date('c'));
        } catch (Throwable $e) {
            // Non-fatal if user lacks ALTER privileges; schema errors will surface cleanly on write.
            error_log('[SchemaMigrator] Migration warning: ' . $e->getMessage());
        }
    }
}
