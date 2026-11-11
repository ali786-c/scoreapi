<?php
// 60Score / STUMPS - 1-Step Automatic Web Installer & Database Migrator
// Allows configuring MySQL credentials via web UI, running migrations automatically,
// and provisioning the master admin account with 1 click.

session_start();

$lockFile = __DIR__ . '/api/config/installed.lock';
$configFile = __DIR__ . '/api/config/db_config.php';
$isInstalled = file_exists($lockFile);

$error = '';
$success = false;

// System compatibility checks
$phpVersionOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$pdoOk = extension_loaded('pdo');
$pdoMysqlOk = extension_loaded('pdo_mysql');
$configWritable = is_writable(__DIR__ . '/api/config') || is_writable(__DIR__ . '/api');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isInstalled) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';

    $adminName = trim($_POST['admin_name'] ?? 'Administrator');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPass = $_POST['admin_pass'] ?? '';

    if (empty($dbName) || empty($dbUser)) {
        $error = 'Database Name and Database Username are required.';
    } elseif (empty($adminEmail) || empty($adminPass)) {
        $error = 'Admin Email and Password are required.';
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid Admin Email address format.';
    } elseif (strlen($adminPass) < 6) {
        $error = 'Admin Password must be at least 6 characters.';
    } else {
        // Attempt MySQL connection and migration
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);

            // 1. Run Table Migrations
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // users
            $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `uid` VARCHAR(64) NOT NULL UNIQUE,
              `user_code` VARCHAR(16) NOT NULL UNIQUE,
              `name` VARCHAR(100) NOT NULL,
              `email` VARCHAR(150) NOT NULL UNIQUE,
              `google_id` VARCHAR(128) NULL UNIQUE,
              `auth_provider` ENUM('google', 'email') NOT NULL DEFAULT 'google',
              `password_hash` VARCHAR(255) NULL DEFAULT NULL,
              `role` ENUM('player', 'scorer', 'admin') DEFAULT 'player',
              `profile_completed` TINYINT(1) NOT NULL DEFAULT 0,
              `contact` VARCHAR(50) DEFAULT NULL,
              `city` VARCHAR(100) DEFAULT '',
              `avatar_url` VARCHAR(255) DEFAULT NULL,
              `api_token` VARCHAR(128) UNIQUE DEFAULT NULL,
              `is_banned` TINYINT(1) DEFAULT 0,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_user_email` (`email`),
              INDEX `idx_user_google` (`google_id`),
              INDEX `idx_user_code` (`user_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // players
            $pdo->exec("CREATE TABLE IF NOT EXISTS `players` (
              `id` VARCHAR(64) PRIMARY KEY,
              `user_code` VARCHAR(16) NOT NULL UNIQUE,
              `linked_user_id` INT DEFAULT NULL,
              `name` VARCHAR(100) NOT NULL,
              `is_verified` TINYINT(1) DEFAULT 0,
              `email` VARCHAR(150) DEFAULT NULL,
              `role` VARCHAR(50) DEFAULT 'All-Rounder',
              `batting_style` VARCHAR(50) DEFAULT 'Right-hand',
              `bowling_style` VARCHAR(50) DEFAULT 'Right-arm medium',
              `jersey_number` INT NOT NULL DEFAULT 7,
              `city` VARCHAR(100) DEFAULT '',
              `photo_base64` MEDIUMTEXT DEFAULT NULL,
              `career_stats_json` JSON DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX `idx_player_user_code` (`user_code`),
              INDEX `idx_player_verified` (`is_verified`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // teams
            $pdo->exec("CREATE TABLE IF NOT EXISTS `teams` (
              `id` VARCHAR(64) PRIMARY KEY,
              `name` VARCHAR(100) NOT NULL,
              `short_name` VARCHAR(10) NOT NULL,
              `color_hex` VARCHAR(10) DEFAULT '#00261B',
              `logo_base64` MEDIUMTEXT DEFAULT NULL,
              `captain_id` VARCHAR(64) DEFAULT NULL,
              `wicket_keeper_id` VARCHAR(64) DEFAULT NULL,
              `creator_id` VARCHAR(64) NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // team_players
            $pdo->exec("CREATE TABLE IF NOT EXISTS `team_players` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `team_id` VARCHAR(64) NOT NULL,
              `player_id` VARCHAR(64) NOT NULL,
              `jersey_no` INT DEFAULT NULL,
              UNIQUE KEY `team_player_unique` (`team_id`, `player_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // matches
            $pdo->exec("CREATE TABLE IF NOT EXISTS `matches` (
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
              INDEX `idx_match_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // tournaments
            $pdo->exec("CREATE TABLE IF NOT EXISTS `tournaments` (
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
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // drafts
            $pdo->exec("CREATE TABLE IF NOT EXISTS `drafts` (
              `id` VARCHAR(64) PRIMARY KEY,
              `tournament_id` VARCHAR(64) NOT NULL,
              `owner_uid` VARCHAR(64) NOT NULL,
              `draft_payload` LONGTEXT NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            // 2. Provision Admin User
            $adminUid = 'usr_admin_' . bin2hex(random_bytes(4));
            $adminCode = '60S-ADMIN1';
            $adminPassHash = password_hash($adminPass, PASSWORD_BCRYPT);
            $adminToken = bin2hex(random_bytes(32));

            $stmtAdmin = $pdo->prepare("INSERT INTO `users` 
                (`uid`, `user_code`, `name`, `email`, `auth_provider`, `password_hash`, `role`, `profile_completed`, `api_token`) 
                VALUES (?, ?, ?, ?, 'email', ?, 'admin', 1, ?)
                ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`), `name` = VALUES(`name`)");
            $stmtAdmin->execute([$adminUid, $adminCode, $adminName, $adminEmail, $adminPassHash, $adminToken]);

            // 3. Write db_config.php file
            $configContent = "<?php\n// 60Score Database Configuration - Generated by 1-Step Web Installer\nreturn [\n" .
                "    'host' => " . var_export($dbHost, true) . ",\n" .
                "    'port' => " . var_export($dbPort, true) . ",\n" .
                "    'database' => " . var_export($dbName, true) . ",\n" .
                "    'username' => " . var_export($dbUser, true) . ",\n" .
                "    'password' => " . var_export($dbPass, true) . ",\n" .
                "];\n";

            file_put_contents($configFile, $configContent);

            // 4. Create installation lock
            file_put_contents($lockFile, "Installed on " . date('c') . " by " . $adminEmail);

            $success = true;

        } catch (PDOException $e) {
            $error = 'Database Connection Failed: ' . $e->getMessage() . '. Please verify your cPanel MySQL database name, user, and password.';
        } catch (Exception $e) {
            $error = 'Installation Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>60Score - 1-Step Automatic Web Installer</title>
  <style>
    :root {
      --primary: #00261B;
      --mint: #7CF8A1;
      --on-mint: #007239;
      --lime: #BBF540;
      --surface: #F9F9FF;
      --border: #CBD5E1;
      --text: #141B2B;
    }
    * { margin:0; padding:0; box-sizing:border-box; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    body { background-color: var(--surface); color: var(--text); padding: 40px 20px; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
    .card { background: #fff; max-width: 620px; width: 100%; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid var(--border); overflow: hidden; }
    .header { background: var(--primary); padding: 32px 36px; color: #fff; }
    .header h1 { font-size: 24px; font-weight: 900; letter-spacing: -0.5px; }
    .header p { color: #94A3B8; font-size: 13.5px; margin-top: 6px; }
    .body { padding: 32px 36px; }
    .system-check { display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
    .check-pill { background: #F1F5F9; font-size: 11.5px; font-weight: 700; padding: 6px 12px; border-radius: 20px; display: flex; align-items: center; gap: 6px; }
    .check-ok { color: var(--on-mint); background: #DCFCE7; }
    .check-fail { color: #991B1B; background: #FEE2E2; }
    .section-title { font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--primary); margin: 20px 0 12px 0; border-bottom: 2px solid #E2E8F0; padding-bottom: 6px; }
    .form-group { margin-bottom: 14px; }
    .form-row { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
    label { display: block; font-size: 12.5px; font-weight: 700; margin-bottom: 5px; color: #475569; }
    input { width: 100%; padding: 11px 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 14px; transition: border 0.2s; }
    input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,38,27,0.1); }
    .btn { background: var(--primary); color: var(--lime); width: 100%; padding: 15px; border: none; border-radius: 12px; font-size: 15px; font-weight: 800; cursor: pointer; margin-top: 20px; transition: opacity 0.2s; }
    .btn:hover { opacity: 0.95; }
    .alert-error { background: #FEE2E2; color: #991B1B; padding: 14px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
    .success-box { text-align: center; padding: 20px 0; }
    .success-icon { font-size: 54px; margin-bottom: 16px; color: var(--on-mint); }
  </style>
</head>
<body>

<div class="card">
  <div class="header">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h1>60Score Setup Wizard</h1>
      <span style="background:var(--lime); color:var(--primary); font-size:11px; font-weight:900; padding:4px 10px; border-radius:6px;">1-STEP</span>
    </div>
    <p>Automatic MySQL Database Provisioning, Schema Migration & Admin Creation.</p>
  </div>

  <div class="body">

    <?php if ($isInstalled && !$success): ?>
      <div class="success-box">
        <div class="success-icon">🔒</div>
        <h2 style="font-size: 20px; font-weight: 800; color: var(--primary);">Backend Already Installed!</h2>
        <p style="font-size: 13.5px; color: #64748B; margin: 12px 0 24px 0;">
          This 60Score backend installation is already active and locked for security.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
          <a href="admin/" style="background:var(--primary); color:var(--lime); padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:800; font-size:14px;">Go to Admin Dashboard</a>
          <a href="api/health" style="background:#E2E8F0; color:#334155; padding:12px 24px; border-radius:10px; text-decoration:none; font-weight:700; font-size:14px;">API Status</a>
        </div>
      </div>

    <?php elseif ($success): ?>
      <div class="success-box">
        <div class="success-icon">✅</div>
        <h2 style="font-size: 22px; font-weight: 900; color: var(--primary);">Installation Completed!</h2>
        <p style="font-size: 14px; color: #475569; margin: 12px 0 24px 0; line-height: 1.5;">
          All <strong>7 database tables</strong> have been created, indexes applied, and your Master Admin account has been activated.
        </p>
        <a href="admin/login.php" style="display:block; background:var(--primary); color:var(--lime); padding:15px; border-radius:12px; text-decoration:none; font-weight:800; font-size:15px;">Launch Admin Dashboard &raquo;</a>
      </div>

    <?php else: ?>

      <!-- Pre-flight checks -->
      <div class="system-check">
        <span class="check-pill <?= $phpVersionOk ? 'check-ok' : 'check-fail' ?>">PHP <?= PHP_VERSION ?> <?= $phpVersionOk ? '✓' : '✗' ?></span>
        <span class="check-pill <?= $pdoMysqlOk ? 'check-ok' : 'check-fail' ?>">PDO MySQL <?= $pdoMysqlOk ? '✓' : '✗' ?></span>
        <span class="check-pill <?= $configWritable ? 'check-ok' : 'check-fail' ?>">Config Writable <?= $configWritable ? '✓' : '✗' ?></span>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="section-title">1. cPanel MySQL Database Credentials</div>

        <div class="form-row">
          <div class="form-group">
            <label>Database Host</label>
            <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
          </div>
          <div class="form-group">
            <label>Port</label>
            <input type="text" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label>Database Name (e.g. yourcpanel_60score)</label>
          <input type="text" name="db_name" placeholder="user_dbname" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Database Username</label>
          <input type="text" name="db_user" placeholder="user_dbuser" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Database Password</label>
          <input type="password" name="db_pass" placeholder="••••••••••••" value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
        </div>

        <div class="section-title" style="margin-top: 24px;">2. Master Admin Account</div>

        <div class="form-group">
          <label>Admin Full Name</label>
          <input type="text" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'Administrator') ?>" required>
        </div>

        <div class="form-group">
          <label>Admin Email</label>
          <input type="email" name="admin_email" placeholder="admin@yourdomain.com" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label>Admin Password (Minimum 6 chars)</label>
          <input type="password" name="admin_pass" placeholder="Choose a strong password" required>
        </div>

        <button type="submit" class="btn">⚡ Run 1-Step Installation & Migrate Database</button>
      </form>

    <?php endif; ?>

  </div>
</div>

</body>
</html>
