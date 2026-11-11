<?php
// 60Score / STUMPS - Root Entry Point
// Automatically detects whether the backend is installed.
// If uninstalled, redirects immediately to the 1-Step Web Installer (install.php).
// If installed, routes to the Admin Dashboard (admin/).

$lockFile = __DIR__ . '/api/config/installed.lock';

if (!file_exists($lockFile)) {
    header('Location: install.php');
    exit;
}

// Redirect to Admin Dashboard
header('Location: admin/');
exit;
