<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../api/config/db.php';
$pdo = Database::getConnection();

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
    $stmt = $pdo->prepare("SELECT * FROM players 
        WHERE name LIKE ? OR user_code LIKE ? OR id LIKE ? 
        ORDER BY created_at DESC LIMIT 50");
    $term = "%{$search}%";
    $stmt->execute([$term, $term, $term]);
    $players = $stmt->fetchAll();
} else {
    $players = $pdo->query("SELECT * FROM players ORDER BY created_at DESC LIMIT 50")->fetchAll();
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
                <td><?= htmlspecialchars($p['email'] ?: 'Local (Not Linked)') ?></td>
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
  </main>

</body>
</html>
