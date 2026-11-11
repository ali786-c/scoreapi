<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../api/config/db.php';
$pdo = Database::getConnection();

// Handle Delete Match
if (isset($_POST['delete_match_id'])) {
    $delId = $_POST['delete_match_id'];
    $delStmt = $pdo->prepare("DELETE FROM matches WHERE id = ?");
    $delStmt->execute([$delId]);
    header('Location: matches.php?msg=deleted');
    exit;
}

// Fetch matches with optional search
$search = trim($_GET['q'] ?? '');
if (!empty($search)) {
    $stmt = $pdo->prepare("SELECT * FROM matches 
        WHERE team_a_name LIKE ? OR team_b_name LIKE ? OR creator_name LIKE ? OR id LIKE ? 
        ORDER BY created_at DESC LIMIT 50");
    $term = "%{$search}%";
    $stmt->execute([$term, $term, $term, $term]);
    $matches = $stmt->fetchAll();
} else {
    $matches = $pdo->query("SELECT * FROM matches ORDER BY created_at DESC LIMIT 50")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Matches Moderator - 60Score Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>

  <aside class="sidebar">
    <div class="brand">60SCORE <span class="tag">ADMIN</span></div>
    <ul class="nav-links">
      <li><a href="index.php">📊 Overview & Live</a></li>
      <li><a href="matches.php" class="active">🏏 Matches & Scores</a></li>
      <li><a href="users.php">👥 Players & Accounts</a></li>
    </ul>
    <div class="nav-user">
      <span><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
      <a href="logout.php">Logout</a>
    </div>
  </aside>

  <main class="main-content">
    <div class="top-bar">
      <div>
        <h1 class="page-title">Match Moderator & Telemetry</h1>
        <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">Inspect ball-by-ball records, creator ownership, and live status</p>
      </div>
      <div>
        <form method="GET" style="display: flex; gap: 8px;">
          <input type="text" name="q" class="search-input" placeholder="Search team, creator, ID..." value="<?= htmlspecialchars($search) ?>">
          <button type="submit" class="btn btn-primary">Search</button>
        </form>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <h2 class="panel-title">Matches Directory (<?= count($matches) ?> shown)</h2>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Teams / Match Title</th>
            <th>Scorer (Creator)</th>
            <th>Status</th>
            <th>Overs</th>
            <th>Result</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($matches)): ?>
            <tr><td colspan="6" style="text-align: center; color: var(--text-muted);">No matches found matching criteria.</td></tr>
          <?php else: ?>
            <?php foreach ($matches as $m): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($m['team_a_name']) ?> vs <?= htmlspecialchars($m['team_b_name']) ?></strong>
                  <div style="font-size: 11px; color: var(--text-muted);">ID: <?= htmlspecialchars($m['id']) ?> | <?= htmlspecialchars($m['venue'] ?: 'Ground') ?></div>
                </td>
                <td>
                  <?= htmlspecialchars($m['creator_name']) ?>
                  <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">Code: <?= htmlspecialchars($m['creator_code']) ?></div>
                </td>
                <td>
                  <span class="badge <?= $m['status'] === 'live' ? 'badge-live' : ($m['status'] === 'completed' ? 'badge-completed' : 'badge-unverified') ?>">
                    <?= strtoupper($m['status']) ?>
                  </span>
                </td>
                <td><?= (int)$m['overs_limit'] ?> Ov</td>
                <td>
                  <div style="font-size: 12px; max-width: 200px;"><?= htmlspecialchars($m['result_text'] ?: '—') ?></div>
                </td>
                <td>
                  <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this match record?');" style="display: inline;">
                    <input type="hidden" name="delete_match_id" value="<?= htmlspecialchars($m['id']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
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
