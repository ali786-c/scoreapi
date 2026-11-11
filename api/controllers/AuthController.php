<?php
// 60Score / STUMPS Backend - AuthController
// Handles registration, login, token generation, and profile management.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/user_code.php';
require_once __DIR__ . '/../middleware/auth.php';

class AuthController {

    public static function register(array $input): void {
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $city = trim($input['city'] ?? '');
        $contact = trim($input['contact'] ?? '');

        if (empty($name) || empty($email) || empty($password)) {
            Response::error('Name, email, and password are required', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email format', 422);
        }

        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters long', 422);
        }

        $pdo = Database::getConnection();

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::error('This email is already registered. Please log in.', 409);
        }

        $uid = 'usr_' . bin2hex(random_bytes(8));
        $userCode = userCodeForUid($uid);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $apiToken = bin2hex(random_bytes(32)); // 64-char secure bearer token

        $pdo->beginTransaction();
        try {
            // Insert User
            $stmt = $pdo->prepare("INSERT INTO users (uid, user_code, name, email, password_hash, contact, city, api_token, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'player')");
            $stmt->execute([$uid, $userCode, $name, $email, $passwordHash, $contact, $city, $apiToken]);
            $userId = $pdo->lastInsertId();

            // Also create or link a Verified Player record with matching userCode
            $stmt = $pdo->prepare("INSERT INTO players (id, user_code, linked_user_id, name, is_verified, email, city) 
                VALUES (?, ?, ?, ?, 1, ?, ?) 
                ON DUPLICATE KEY UPDATE linked_user_id = VALUES(linked_user_id), is_verified = 1, email = VALUES(email)");
            $stmt->execute([$uid, $userCode, $userId, $name, $email, $city]);

            $pdo->commit();

            Response::success([
                'user' => [
                    'uid' => $uid,
                    'user_code' => $userCode,
                    'name' => $name,
                    'email' => $email,
                    'is_verified' => true,
                    'city' => $city,
                    'role' => 'player'
                ],
                'token' => $apiToken
            ], 'Account created successfully', 201);

        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Failed to register account: ' . $e->getMessage(), 500);
        }
    }

    public static function login(array $input): void {
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            Response::error('Email and password are required', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid email or password', 401);
        }

        if ($user['is_banned']) {
            Response::forbidden('Your account has been suspended. Please contact administrator.');
        }

        // Generate fresh token if null or refresh
        $token = $user['api_token'];
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            $update = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
            $update->execute([$token, $user['id']]);
        }

        Response::success([
            'user' => [
                'uid' => $user['uid'],
                'user_code' => $user['user_code'],
                'name' => $user['name'],
                'email' => $user['email'],
                'is_verified' => true,
                'role' => $user['role'],
                'city' => $user['city'],
                'contact' => $user['contact'],
                'avatar_url' => $user['avatar_url']
            ],
            'token' => $token
        ], 'Login successful');
    }

    public static function me(): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();

        // Fetch linked player stats if present
        $stmt = $pdo->prepare("SELECT * FROM players WHERE linked_user_id = ? OR id = ? LIMIT 1");
        $stmt->execute([$user['id'], $user['uid']]);
        $player = $stmt->fetch();

        Response::success([
            'user' => [
                'uid' => $user['uid'],
                'user_code' => $user['user_code'],
                'name' => $user['name'],
                'email' => $user['email'],
                'is_verified' => true,
                'role' => $user['role'],
                'city' => $user['city'],
                'contact' => $user['contact'],
                'avatar_url' => $user['avatar_url'],
                'player_profile' => $player ? [
                    'id' => $player['id'],
                    'user_code' => $player['user_code'],
                    'role' => $player['role'],
                    'batting_style' => $player['batting_style'],
                    'bowling_style' => $player['bowling_style'],
                    'career_stats' => json_decode($player['career_stats_json'] ?? '{}', true)
                ] : null
            ]
        ]);
    }

    public static function updateProfile(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();

        $name = trim($input['name'] ?? $user['name']);
        $city = trim($input['city'] ?? $user['city']);
        $contact = trim($input['contact'] ?? $user['contact']);
        $avatarUrl = trim($input['avatar_url'] ?? $user['avatar_url']);

        $stmt = $pdo->prepare("UPDATE users SET name = ?, city = ?, contact = ?, avatar_url = ? WHERE id = ?");
        $stmt->execute([$name, $city, $contact, $avatarUrl, $user['id']]);

        // Keep player record in sync
        $stmtP = $pdo->prepare("UPDATE players SET name = ?, city = ? WHERE linked_user_id = ? OR id = ?");
        $stmtP->execute([$name, $city, $user['id'], $user['uid']]);

        Response::success(null, 'Profile updated successfully');
    }
}
