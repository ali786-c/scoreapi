<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../api/config/db.php';
require_once __DIR__ . '/../api/helpers/schema_migrator.php';
$pdo = Database::getConnection();
SchemaMigrator::ensureGoogleAuthColumns($pdo);

// Handle Toggle Player Verification
if (isset($_POST['toggle_verify_player_id'])) {
    $pid = $_POST['toggle_verify_player_id'];
    $currentStatus = (int)$_POST['current_status'];
    $newStatus = $currentStatus ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE players SET is_verified = ? WHERE id = ?");
    $stmt->execute([$newStatus, $pid]);
    header('Location: users.php?msg=updated');
    exit;
}

// Handle Ban/Unban User
if (isset($_POST['toggle_ban_user_id'])) {
    $uid = $_POST['toggle_ban_user_id'];
    $currentBan = (int)$_POST['current_ban'];
    $newBan = $currentBan ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE users SET is_banned = ? WHERE id = ?");
    $stmt->execute([$newBan, $uid]);
    header('Location: users.php?msg=user_updated');
    exit;
}

$search = trim($_GET['q'] ?? '');
if (!empty($search)) {
    $stmt = $pdo->prepare("SELECT p.*, u.auth_provider, u.profile_completed FROM players p
        LEFT JOIN users u ON p.linked_user_id = u.id
        WHERE p.name LIKE ? OR p.user_code LIKE ? OR p.id LIKE ? OR p.email LIKE ?
        ORDER BY p.created_at DESC LIMIT 50");
    $term = "%{$search}%";
    $stmt->execute([$term, $term, $term, $term]);
    $players = $stmt->fetchAll();

    $stmtU = $pdo->prepare("SELECT * FROM users
        WHERE name LIKE ? OR user_code LIKE ? OR email LIKE ?
        ORDER BY created_at DESC LIMIT 50");
    $stmtU->execute([$term, $term, $term]);
    $usersList = $stmtU->fetchAll();
} else {
    $players = $pdo->query("SELECT p.*, u.auth_provider, u.profile_completed FROM players p LEFT JOIN users u ON p.linked_user_id = u.id ORDER BY p.created_at DESC LIMIT 50")->fetchAll();
    $usersList = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 50")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Players & Accounts - 60Score Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>

  <aside class="sidebar">
    <div class="brand">60SCORE <span class="tag">ADMIN</span></div>
    <ul class="nav-links">
      <li><a href="index.php">📊 Overview & Live</a></li>
      <li><a href="matches.php">🏏 Matches & Scores</a></li>
      <li><a href="users.php" class="active">👥 Players & Accounts</a></li>
    </ul>
    <div class="nav-user">
      <span><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
      <a href="logout.php">Logout</a>
    </div>
  </aside>

  <main class="main-content">
    <div class="top-bar">
      <div>
        <h1 class="page-title">Players & Identity Management</h1>
        <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">Search players by Unique ID (60S-XXXXXX) or toggle verification badges</p>
      </div>
      <div>
        <form method="GET" style="display: flex; gap: 8px;">
          <input type="text" name="q" class="search-input" placeholder="Search name, 60S- code..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary">Search</button>
        </form>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <h2 class="panel-title">Player Profiles (<?= count($players) ?> shown)</h2>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Player Name</th>
            <th>Unique Code (ID)</th>
            <th>Verification Status</th>
            <th>Linked Email</th>
            <th>Role / City</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($players)): ?>
            <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No player records found.</td></tr>
          <?php else: ?>
            <?php foreach ($players as $p): ?>
              <tr>
                <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                <td><code style="background: #F1F5F9; padding: 3px 8px; border-radius: 4px; font-weight: 700; color: #00261B;"><?= htmlspecialchars($p['user_code']) ?></code></td>
                <td>
                  <?php if ($p['is_verified']): ?>
                    <span class="badge badge-verified">✓ VERIFIED</span>
                  <?php else: ?>
                    <span class="badge badge-unverified">UNVERIFIED</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?= htmlspecialchars($p['email'] ?: 'Local (Not Linked)') ?>
                  <?php if (($p['auth_provider'] ?? '') === 'google'): ?>
                    <span style="display:inline-block; background:#E0F2FE; color:#0369A1; font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px; margin-left:4px;">GOOGLE</span>
                  <?php endif; ?>
                  <?php if (isset($p['profile_completed']) && !$p['profile_completed']): ?>
                    <span style="display:inline-block; background:#FEF3C7; color:#92400E; font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px; margin-left:4px;">ONBOARDING PENDING</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($p['role']) ?> <?= !empty($p['city']) ? '• ' . htmlspecialchars($p['city']) : '' ?></td>
                <td>
                  <form method="POST" style="display: inline;">
                    <input type="hidden" name="toggle_verify_player_id" value="<?= htmlspecialchars($p['id']) ?>">
                    <input type="hidden" name="current_status" value="<?= (int)$p['is_verified'] ?>">
                    <button type="submit" class="btn <?= $p['is_verified'] ? 'btn-danger' : 'btn-primary' ?> btn-sm">
                      <?= $p['is_verified'] ? 'Revoke Verification' : 'Grant Verified Badge' ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="panel" style="margin-top: 24px;">
      <div class="panel-header">
        <h2 class="panel-title">Registered Accounts (<?= count($usersList) ?> shown)</h2>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Account Name</th>
            <th>60S Code</th>
            <th>Email</th>
            <th>Provider</th>
            <th>Onboarding</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($usersList)): ?>
            <tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No user accounts found.</td></tr>
          <?php else: ?>
            <?php foreach ($usersList as $u): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($u['name']) ?></strong>
                  <div style="font-size:11px; color:var(--text-muted);"><?= strtoupper(htmlspecialchars($u['role'])) ?></div>
                </td>
                <td><code style="background: #F1F5F9; padding: 3px 8px; border-radius: 4px; font-weight: 700; color: #00261B;"><?= htmlspecialchars($u['user_code']) ?></code></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <?php if (($u['auth_provider'] ?? '') === 'google'): ?>
                    <span class="badge badge-verified">GOOGLE</span>
                  <?php else: ?>
                    <span class="badge badge-unverified">EMAIL</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($u['profile_completed'])): ?>
                    <span class="badge badge-verified">✓ COMPLETED</span>
                  <?php else: ?>
                    <span class="badge badge-unverified">PENDING</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($u['is_banned'])): ?>
                    <span class="badge badge-live">BANNED</span>
                  <?php else: ?>
                    <span class="badge badge-verified">ACTIVE</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($u['role'] !== 'admin'): ?>
                    <form method="POST" style="display: inline;">
                      <input type="hidden" name="toggle_ban_user_id" value="<?= (int)$u['id'] ?>">
                      <input type="hidden" name="current_ban" value="<?= (int)$u['is_banned'] ?>">
                      <button type="submit" class="btn <?= $u['is_banned'] ? 'btn-primary' : 'btn-danger' ?> btn-sm">
                        <?= $u['is_banned'] ? 'Unban User' : 'Ban User' ?>
                      </button>
                    </form>
                  <?php else: ?>
                    <span style="font-size:11px; color:var(--text-muted);">Master Admin</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>

</body>
</html>
