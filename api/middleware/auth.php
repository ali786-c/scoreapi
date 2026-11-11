<?php
// 60Score / STUMPS Backend - Authentication Middleware
// Validates Bearer tokens from the Authorization header and resolves the current user.

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../config/db.php';

class AuthMiddleware {
    private static ?array $currentUser = null;

    public static function authenticate(bool $required = true): ?array {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $token = self::getBearerToken();
        if (!$token) {
            if ($required) {
                Response::unauthorized('Bearer token missing in Authorization header');
            }
            return null;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE api_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            if ($required) {
                Response::unauthorized('Account deleted or authentication token is invalid');
            }
            return null;
        }

        if (!empty($user['is_banned'])) {
            if ($required) {
                Response::forbidden('Your account has been banned by an administrator.');
            }
            return null;
        }

        self::$currentUser = $user;
        return $user;
    }

    public static function requireAdmin(): array {
        $user = self::authenticate(true);
        if ($user['role'] !== 'admin') {
            Response::forbidden('Administrator privileges required');
        }
        return $user;
    }

    public static function user(): ?array {
        return self::$currentUser;
    }

    private static function getBearerToken(): ?string {
        $headers = self::getRequestHeaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        // Fallback for query param ?token=... (useful for testing or event stream/websockets)
        if (!empty($_GET['token'])) {
            return trim($_GET['token']);
        }

        return null;
    }

    private static function getRequestHeaders(): array {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
}
