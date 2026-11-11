<?php
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../api/config/db.php';
require_once __DIR__ . '/../api/helpers/schema_migrator.php';
require_once __DIR__ . '/../api/helpers/firebase_admin.php';
$pdo = Database::getConnection();
SchemaMigrator::ensureGoogleAuthColumns($pdo);

// Handle Optional Firebase Service Account Save
if (isset($_POST['firebase_sa_json'])) {
    $rawJson = trim($_POST['firebase_sa_json']);
    $saPath = __DIR__ . '/../api/config/firebase_service_account.json';
    if ($rawJson === '') {
        if (file_exists($saPath)) @unlink($saPath);
        header('Location: users.php?msg=fb_removed');
        exit;
    }
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded) || empty($decoded['project_id']) || empty($decoded['private_key']) || empty($decoded['client_email'])) {
        header('Location: users.php?msg=fb_invalid');
        exit;
    }
    @file_put_contents($saPath, json_encode($decoded, JSON_PRETTY_PRINT));
    header('Location: users.php?msg=fb_saved');
    exit;
}

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
    $uid = (int)$_POST['toggle_ban_user_id'];
    $currentBan = (int)$_POST['current_ban'];
    $newBan = $currentBan ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE users SET is_banned = ? WHERE id = ? AND role != 'admin'");
    $stmt->execute([$newBan, $uid]);
    header('Location: users.php?msg=user_updated');
    exit;
}

// Handle Complete User Deletion (MySQL + Optional Firebase Auth)
if (isset($_POST['delete_user_id'])) {
    $targetId = (int)$_POST['delete_user_id'];
    $stmtFetch = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role != 'admin' LIMIT 1");
    $stmtFetch->execute([$targetId]);
    $targetUser = $stmtFetch->fetch();

    if ($targetUser) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM players WHERE linked_user_id = ? OR id = ? OR user_code = ?")
                ->execute([$targetUser['id'], $targetUser['uid'], $targetUser['user_code']]);
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")
                ->execute([$targetUser['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            header('Location: users.php?msg=delete_error');
            exit;
        }

        $fbRes = FirebaseAdminHelper::deleteUserByEmail($targetUser['email'] ?? '');
        $fbStatus = $fbRes['status'] ?? 'not_configured';
        header('Location: users.php?msg=user_deleted&fb=' . urlencode($fbStatus));
        exit;
    }
    header('Location: users.php?msg=delete_denied');
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

    <?php if (!empty($_GET['msg'])): ?>
      <?php
        $msgCode = $_GET['msg'];
        $fbNote = $_GET['fb'] ?? '';
        $alertText = match ($msgCode) {
            'updated' => 'Player verification status updated.',
            'user_updated' => 'User ban/active status updated. Banned users are automatically signed out on their next online check.',
            'user_deleted' => 'User & player profile deleted from MySQL.' . ($fbNote === 'deleted_from_firebase' ? ' Also deleted from Firebase Auth!' : ($fbNote === 'not_in_firebase' ? ' (Not present in Firebase Auth.)' : '')),
            'fb_saved' => 'Firebase Service Account saved! Deletions will now sync to Firebase Auth.',
            'fb_removed' => 'Firebase Service Account removed.',
            'fb_invalid' => 'Invalid Service Account JSON. Ensure project_id, client_email, and private_key are present.',
            default => 'Action completed.',
        };
      ?>
      <div style="background:#DCFCE7; color:#166534; padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13px; font-weight:700;">
        <?= htmlspecialchars($alertText) ?>
      </div>
    <?php endif; ?>

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
                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                      <form method="POST" style="display: inline;">
                        <input type="hidden" name="toggle_ban_user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="current_ban" value="<?= (int)$u['is_banned'] ?>">
                        <button type="submit" class="btn <?= $u['is_banned'] ? 'btn-primary' : 'btn-danger' ?> btn-sm">
                          <?= $u['is_banned'] ? 'Unban' : 'Ban' ?>
                        </button>
                      </form>
                      <form method="POST" style="display: inline;" onsubmit="return confirm('Permanently delete user <?= htmlspecialchars(addslashes($u['email'])) ?> and their player profile? This forces sign-out on their app and removes Firebase Auth record if configured.');">
                        <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm" style="background:#7F1D1D;">
                          Delete
                        </button>
                      </form>
                    </div>
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

    <!-- Optional Firebase Auth Admin Sync Panel -->
    <div class="panel" style="margin-top: 24px;">
      <div class="panel-header">
        <h2 class="panel-title">🔥 Firebase Auth Sync (Optional Auto-Delete)</h2>
        <?php if (FirebaseAdminHelper::isConfigured()): ?>
          <span class="badge badge-verified">CONNECTED</span>
        <?php else: ?>
          <span class="badge badge-unverified">NOT CONFIGURED</span>
        <?php endif; ?>
      </div>
      <div style="padding: 16px; font-size: 13px; color: var(--text-muted); line-height: 1.5;">
        <p style="margin-bottom: 10px;">
          Note: App Google Sign-In authenticates directly with this <strong>cPanel MySQL backend</strong> (deleting/banning above immediately blocks &amp; signs out the user in the app). If you also have users inside <strong>Firebase Console &rarr; Authentication</strong> and want Admin Panel deletions to automatically remove them from Firebase Auth as well, paste your Firebase Service Account JSON below (from Firebase Project Settings &rarr; Service accounts &rarr; Generate new private key).
        </p>
        <form method="POST">
          <textarea name="firebase_sa_json" rows="3" placeholder='Paste Firebase service-account.json here (or leave empty and submit to disconnect)...' style="width:100%; padding:10px; border:1px solid #CBD5E1; border-radius:8px; font-family:monospace; font-size:12px;"></textarea>
          <div style="margin-top:10px;">
            <button type="submit" class="btn btn-primary btn-sm">Save Firebase Service Account</button>
          </div>
        </form>
      </div>
    </div>
  </main>

</body>
</html>
