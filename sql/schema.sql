-- 60Score / STUMPS - Production cPanel MySQL Database Schema
-- Charset: utf8mb4, Engine: InnoDB
-- Fully aligned with 60Score Flutter Domain Models

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Users Table (Account & Authentication)
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `uid` VARCHAR(64) NOT NULL UNIQUE,
  `user_code` VARCHAR(16) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('player', 'scorer', 'admin') DEFAULT 'player',
  `contact` VARCHAR(50) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT '',
  `avatar_url` VARCHAR(255) DEFAULT NULL,
  `api_token` VARCHAR(128) UNIQUE DEFAULT NULL,
  `is_banned` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_user_email` (`email`),
  INDEX `idx_user_code` (`user_code`),
  INDEX `idx_api_token` (`api_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Players Table (Verified & Unverified Players)
DROP TABLE IF EXISTS `players`;
CREATE TABLE `players` (
  `id` VARCHAR(64) PRIMARY KEY,
  `user_code` VARCHAR(16) NOT NULL UNIQUE,
  `linked_user_id` INT DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_verified` TINYINT(1) DEFAULT 0,
  `email` VARCHAR(150) DEFAULT NULL,
  `role` VARCHAR(50) DEFAULT 'All-Rounder',
  `batting_style` VARCHAR(50) DEFAULT 'Right-hand',
  `bowling_style` VARCHAR(50) DEFAULT 'Right-arm medium',
  `city` VARCHAR(100) DEFAULT '',
  `photo_base64` MEDIUMTEXT DEFAULT NULL,
  `career_stats_json` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_player_user_code` (`user_code`),
  INDEX `idx_player_verified` (`is_verified`),
  INDEX `idx_player_name` (`name`),
  FOREIGN KEY (`linked_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Teams Table
DROP TABLE IF EXISTS `teams`;
CREATE TABLE `teams` (
  `id` VARCHAR(64) PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `short_name` VARCHAR(10) NOT NULL,
  `color_hex` VARCHAR(10) DEFAULT '#00261B',
  `logo_base64` MEDIUMTEXT DEFAULT NULL,
  `captain_id` VARCHAR(64) DEFAULT NULL,
  `wicket_keeper_id` VARCHAR(64) DEFAULT NULL,
  `creator_id` VARCHAR(64) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_team_name` (`name`),
  INDEX `idx_team_creator` (`creator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Team Players Pivot Table
DROP TABLE IF EXISTS `team_players`;
CREATE TABLE `team_players` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `team_id` VARCHAR(64) NOT NULL,
  `player_id` VARCHAR(64) NOT NULL,
  `jersey_no` INT DEFAULT NULL,
  FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`player_id`) REFERENCES `players`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `team_player_unique` (`team_id`, `player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Matches Table (Matches & Complete Telemetry)
DROP TABLE IF EXISTS `matches`;
CREATE TABLE `matches` (
  `id` VARCHAR(64) PRIMARY KEY,
  `tournament_id` VARCHAR(64) DEFAULT NULL,
  `creator_id` VARCHAR(64) NOT NULL,
  `creator_code` VARCHAR(16) NOT NULL,
  `creator_name` VARCHAR(100) NOT NULL,
  `team_a_id` VARCHAR(64) NOT NULL,
  `team_b_id` VARCHAR(64) NOT NULL,
  `team_a_name` VARCHAR(100) NOT NULL,
  `team_b_name` VARCHAR(100) NOT NULL,
  `overs_limit` INT NOT NULL DEFAULT 20,
  `balls_per_over` INT NOT NULL DEFAULT 6,
  `venue` VARCHAR(150) DEFAULT '',
  `date_iso` VARCHAR(50) DEFAULT '',
  `toss_winner_id` VARCHAR(64) DEFAULT '',
  `toss_decision` ENUM('bat', 'bowl') DEFAULT 'bat',
  `status` ENUM('upcoming', 'live', 'completed', 'noResult', 'cancelled') DEFAULT 'upcoming',
  `stage` VARCHAR(50) DEFAULT 'league',
  `result_text` VARCHAR(255) DEFAULT '',
  `man_of_match_id` VARCHAR(64) DEFAULT NULL,
  `match_title` VARCHAR(150) DEFAULT '',
  `ball_type` VARCHAR(50) DEFAULT 'Tennis Ball',
  `season` VARCHAR(20) DEFAULT '2025',
  `match_payload` LONGTEXT NOT NULL,
  `updated_at_epoch` BIGINT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_match_creator` (`creator_id`),
  INDEX `idx_match_status` (`status`),
  INDEX `idx_match_tournament` (`tournament_id`),
  INDEX `idx_match_updated_epoch` (`updated_at_epoch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tournaments Table
DROP TABLE IF EXISTS `tournaments`;
CREATE TABLE `tournaments` (
  `id` VARCHAR(64) PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `owner_uid` VARCHAR(64) NOT NULL,
  `venue` VARCHAR(150) DEFAULT '',
  `logo_base64` MEDIUMTEXT DEFAULT NULL,
  `format` VARCHAR(50) DEFAULT 'Round Robin',
  `status` ENUM('draft', 'ongoing', 'completed') DEFAULT 'draft',
  `is_published` TINYINT(1) DEFAULT 0,
  `tournament_payload` LONGTEXT NOT NULL,
  `updated_at_epoch` BIGINT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tournament_owner` (`owner_uid`),
  INDEX `idx_tournament_published` (`is_published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Drafts Table
DROP TABLE IF EXISTS `drafts`;
CREATE TABLE `drafts` (
  `id` VARCHAR(64) PRIMARY KEY,
  `tournament_id` VARCHAR(64) NOT NULL,
  `owner_uid` VARCHAR(64) NOT NULL,
  `draft_payload` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_draft_tournament` (`tournament_id`),
  INDEX `idx_draft_owner` (`owner_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Seed Default Admin User
-- Email: admin@60score.com | Password: password123 (Change upon first login)
INSERT INTO `users` (`uid`, `user_code`, `name`, `email`, `password_hash`, `role`)
VALUES (
  'usr_admin_master',
  '60S-ADMIN1',
  'Master Administrator',
  'admin@60score.com',
  '$2y$10$w8/mH9yI9b6m6qX9kH5U2e5WjVf.K/XQ3K8p.pZ8j7QWqLdJ5fE7u', -- bcrypt for 'password123'
  'admin'
) ON DUPLICATE KEY UPDATE `id`=`id`;

SET FOREIGN_KEY_CHECKS = 1;
