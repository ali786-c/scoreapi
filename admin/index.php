<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../api/config/db.php';
require_once __DIR__ . '/../api/helpers/schema_migrator.php';

$pdo = Database::getConnection();
SchemaMigrator::ensureSchemaUpToDate($pdo);

// 1. Fetch KPI metrics
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$verifiedPlayers = $pdo->query("SELECT COUNT(*) FROM players WHERE is_verified = 1")->fetchColumn();
$totalMatches = $pdo->query("SELECT COUNT(*) FROM matches")->fetchColumn();
$liveMatchesCount = $pdo->query("SELECT COUNT(*) FROM matches WHERE status = 'live'")->fetchColumn();
$totalTeams = $pdo->query("SELECT COUNT(*) FROM teams")->fetchColumn();
$totalTournaments = $pdo->query("SELECT COUNT(*) FROM tournaments")->fetchColumn();
$totalDrafts = $pdo->query("SELECT COUNT(*) FROM drafts")->fetchColumn();
$totalRegPlayers = $pdo->query("SELECT COUNT(*) FROM reg_players")->fetchColumn();
$totalManualStats = $pdo->query("SELECT COUNT(*) FROM manual_stats")->fetchColumn();

// 2. Fetch Live Matches
$liveMatches = $pdo->query("SELECT * FROM matches WHERE status = 'live' ORDER BY updated_at DESC LIMIT 10")->fetchAll();

// 3. Fetch Recent Matches
$recentMatches = $pdo->query("SELECT * FROM matches ORDER BY created_at DESC LIMIT 8")->fetchAll();

// 4. Fetch Recent Players
$recentPlayers = $pdo->query("SELECT * FROM players ORDER BY created_at DESC LIMIT 8")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>60Score - Admin Dashboard</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      60SCORE <span class="tag">ADMIN</span>
    </div>
    <ul class="nav-links">
      <li><a href="index.php" class="active">📊 Overview & Live</a></li>
      <li><a href="matches.php">🏏 Matches & Scores</a></li>
      <li><a href="users.php">👥 Players & Accounts</a></li>
    </ul>
    <div class="nav-user">
      <span><?= htmlspecialchars($_SESSION['admin_name']) ?></span>
      <a href="logout.php">Logout</a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="main-content">
    <div class="top-bar">
      <div>
        <h1 class="page-title">Operational Telemetry</h1>
        <p style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">60Score cPanel Backend Monitoring Dashboard</p>
      </div>
      <div>
        <a href="index.php" class="btn btn-primary">⟳ Refresh Live Telemetry</a>
      </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Active Live Matches</div>
        <div class="kpi-val highlight"><?= $liveMatchesCount ?></div>
        <div class="kpi-sub">Scored in real-time</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Total Matches Scored</div>
        <div class="kpi-val"><?= $totalMatches ?></div>
        <div class="kpi-sub">Lifetime matches in MySQL</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Verified Players</div>
        <div class="kpi-val highlight"><?= $verifiedPlayers ?></div>
        <div class="kpi-sub">Linked to authenticated emails</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Registered Accounts</div>
        <div class="kpi-val"><?= $totalUsers ?></div>
        <div class="kpi-sub">Active app users & scorers</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Teams & Squads</div>
        <div class="kpi-val"><?= $totalTeams ?></div>
        <div class="kpi-sub">Teams synced to MySQL</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Tournaments</div>
        <div class="kpi-val"><?= $totalTournaments ?></div>
        <div class="kpi-sub">Leagues & playoff brackets</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Drafts / Pool</div>
        <div class="kpi-val"><?= $totalDrafts ?> / <?= $totalRegPlayers ?></div>
        <div class="kpi-sub">Drafts & registered players</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Manual Career Stats</div>
        <div class="kpi-val"><?= $totalManualStats ?></div>
        <div class="kpi-sub">Past innings & bowling records</div>
      </div>
    </div>

    <!-- Live Matches Section -->
    <?php if ($liveMatchesCount > 0): ?>
    <div class="panel">
      <div class="panel-header">
        <h2 class="panel-title">🔴 Currently Live Matches</h2>
        <span class="badge badge-live">Live Feed</span>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Match Title / Teams</th>
            <th>Scorer (Creator)</th>
            <th>Venue</th>
            <th>Overs Limit</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($liveMatches as $m): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($m['team_a_name']) ?> vs <?= htmlspecialchars($m['team_b_name']) ?></strong>
                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($m['match_title'] ?: 'Regular Fixture') ?></div>
              </td>
              <td>
                <?= htmlspecialchars($m['creator_name']) ?>
                <div style="font-size: 11px; color: var(--text-muted);">ID: <?= htmlspecialchars($m['creator_code']) ?></div>
              </td>
              <td><?= htmlspecialchars($m['venue'] ?: 'Ground') ?></td>
              <td><?= (int)$m['overs_limit'] ?> Ov</td>
              <td><span class="badge badge-live">LIVE SCORING</span></td>
              <td>
                <a href="matches.php?id=<?= urlencode($m['id']) ?>" class="btn btn-primary btn-sm">Inspect</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Recent Matches & Recent Players Grid -->
    <div style="display: grid; grid-template-columns: 3fr 2fr; gap: 24px;">
      
      <!-- Recent Matches -->
      <div class="panel">
        <div class="panel-header">
          <h2 class="panel-title">Recent Matches</h2>
          <a href="matches.php" style="font-size: 12px; color: var(--secondary); font-weight: 700; text-decoration: none;">View All</a>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Teams</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentMatches)): ?>
              <tr><td colspan="3" style="text-align: center; color: var(--text-muted);">No matches recorded yet.</td></tr>
            <?php else: ?>
              <?php foreach ($recentMatches as $rm): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($rm['team_a_name']) ?> vs <?= htmlspecialchars($rm['team_b_name']) ?></strong>
                    <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($rm['result_text'] ?: 'In progress') ?></div>
                  </td>
                  <td>
                    <span class="badge <?= $rm['status'] === 'live' ? 'badge-live' : ($rm['status'] === 'completed' ? 'badge-completed' : 'badge-unverified') ?>">
                      <?= strtoupper($rm['status']) ?>
                    </span>
                  </td>
                  <td style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars(substr($rm['created_at'], 0, 10)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Recent Players -->
      <div class="panel">
        <div class="panel-header">
          <h2 class="panel-title">Player Profiles</h2>
          <a href="users.php" style="font-size: 12px; color: var(--secondary); font-weight: 700; text-decoration: none;">View All</a>
        </div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Player / ID</th>
              <th>Verification</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentPlayers)): ?>
              <tr><td colspan="2" style="text-align: center; color: var(--text-muted);">No players found.</td></tr>
            <?php else: ?>
              <?php foreach ($recentPlayers as $rp): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($rp['name']) ?></strong>
                    <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">ID: <?= htmlspecialchars($rp['user_code']) ?></div>
                  </td>
                  <td>
                    <?php if ($rp['is_verified']): ?>
                      <span class="badge badge-verified">✓ VERIFIED</span>
                    <?php else: ?>
                      <span class="badge badge-unverified">UNVERIFIED</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </main>

</body>
</html>
