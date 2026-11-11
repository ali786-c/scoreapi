<?php
session_start();

// If not installed yet, redirect to 1-Step Web Installer
if (!file_exists(__DIR__ . '/../api/config/installed.lock')) {
    header('Location: ../install.php');
    exit;
}

require_once __DIR__ . '/../api/config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_email'] = $admin['email'];
                header('Location: index.php');
                exit;
            } else {
                $error = 'Invalid administrator credentials.';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please enter both email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login - 60Score</title>
  <link rel="stylesheet" href="assets/admin.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: #00261B;
    }
    .login-box {
      background: #fff;
      padding: 40px;
      border-radius: 16px;
      width: 100%;
      max-width: 420px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.3);
    }
    .login-title {
      font-size: 22px;
      font-weight: 800;
      color: #00261B;
      margin-bottom: 8px;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 6px;
      color: #334155;
    }
    .form-control {
      width: 100%;
      padding: 12px;
      border: 1px solid #CBD5E1;
      border-radius: 8px;
      font-size: 14px;
    }
    .alert-error {
      background: #FEE2E2;
      color: #BA1A1A;
      padding: 12px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 20px;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <div class="login-box">
    <div style="margin-bottom: 24px;">
      <span class="badge badge-verified" style="margin-bottom: 8px;">60Score Master Control</span>
      <h1 class="login-title">Administrator Portal</h1>
      <p style="font-size: 13px; color: #64748B;">Sign in to monitor live matches, players, and system health.</p>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label>Admin Email</label>
        <input type="email" name="email" class="form-control" placeholder="admin@60score.com" required>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-size: 14px;">Sign In to Dashboard</button>
    </form>
  </div>
</body>
</html>
