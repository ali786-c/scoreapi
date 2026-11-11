-- 60Score / STUMPS - Non-destructive Migration for Google Sign-In & Onboarding
-- Use this only if auto-migration cannot run due to restricted DB ALTER privileges.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `google_id` VARCHAR(128) NULL UNIQUE AFTER `email`,
  ADD COLUMN IF NOT EXISTS `auth_provider` ENUM('google', 'email') NOT NULL DEFAULT 'email' AFTER `google_id`,
  MODIFY COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `profile_completed` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`;

ALTER TABLE `players`
  ADD COLUMN IF NOT EXISTS `jersey_number` INT NOT NULL DEFAULT 7 AFTER `bowling_style`;
