<?php
// 60Score / STUMPS Backend - AuthController
// Handles Google Sign-In verification, first-time onboarding, token generation, and profile management.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/user_code.php';
require_once __DIR__ . '/../helpers/schema_migrator.php';
require_once __DIR__ . '/../middleware/auth.php';

class AuthController {

    private static function formatUserPayload(array $user, ?array $player = null): array {
        return [
            'uid' => $user['uid'],
            'user_code' => $user['user_code'],
            'name' => $user['name'],
            'email' => $user['email'],
            'auth_provider' => $user['auth_provider'] ?? 'google',
            'is_verified' => true,
            'profile_completed' => (bool)($user['profile_completed'] ?? true),
            'role' => $user['role'],
            'city' => $user['city'] ?? '',
            'contact' => $user['contact'] ?? '',
            'avatar_url' => $user['avatar_url'] ?? null,
            'player_profile' => $player ? [
                'id' => $player['id'],
                'user_code' => $player['user_code'],
                'name' => $player['name'],
                'role' => $player['role'] ?? 'All-Rounder',
                'batting_style' => $player['batting_style'] ?? 'Right-hand bat',
                'bowling_style' => $player['bowling_style'] ?? 'Right-arm fast-medium',
                'jersey_number' => (int)($player['jersey_number'] ?? 7),
                'city' => $player['city'] ?? '',
                'career_stats' => json_decode($player['career_stats_json'] ?? '{}', true)
            ] : null
        ];
    }

    private static function fetchPlayerForUser(PDO $pdo, array $user): ?array {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE linked_user_id = ? OR id = ? OR user_code = ? LIMIT 1");
        $stmt->execute([$user['id'], $user['uid'], $user['user_code']]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function httpGetJson(string $url, array $headers = []): ?array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body !== false && $code >= 200 && $code < 300) {
                $decoded = json_decode($body, true);
                return is_array($decoded) ? $decoded : null;
            }
            return null;
        }

        // Fallback if cURL extension is disabled
        $headerStr = !empty($headers) ? implode("\r\n", $headers) . "\r\n" : '';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headerStr,
                'timeout' => 12,
                'ignore_errors' => true,
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Verifies a Google ID token (Android/iOS) or OAuth access token (Flutter Web)
     * against Google's official endpoints without external Composer dependencies.
     */
    private static function verifyGoogleTokens(?string $idToken, ?string $accessToken): array {
        $cfg = require __DIR__ . '/../config/config.php';
        $allowedAudiences = $cfg['google_client_ids'] ?? [];

        if (!empty($idToken)) {
            $info = self::httpGetJson('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
            if (!$info || empty($info['sub']) || empty($info['email'])) {
                Response::unauthorized('Invalid or expired Google ID token.');
            }
            $emailVerified = $info['email_verified'] ?? false;
            if ($emailVerified !== true && $emailVerified !== 'true' && $emailVerified !== '1' && $emailVerified !== 1) {
                Response::unauthorized('Google account email is not verified.');
            }
            if (!empty($info['exp']) && (int)$info['exp'] < (time() - 120)) {
                Response::unauthorized('Google ID token has expired. Please sign in again.');
            }
            if (!empty($allowedAudiences)) {
                $aud = $info['aud'] ?? '';
                $azp = $info['azp'] ?? '';
                if (!in_array($aud, $allowedAudiences, true) && !in_array($azp, $allowedAudiences, true)) {
                    Response::unauthorized('Google token audience mismatch.');
                }
            }
            return [
                'sub' => (string)$info['sub'],
                'email' => strtolower(trim((string)$info['email'])),
                'name' => trim((string)($info['name'] ?? '')),
                'avatar_url' => trim((string)($info['picture'] ?? '')),
            ];
        }

        if (!empty($accessToken)) {
            // 1. Validate tokeninfo to confirm audience/expiry
            $tInfo = self::httpGetJson('https://oauth2.googleapis.com/tokeninfo?access_token=' . urlencode($accessToken));
            if (!$tInfo || (isset($tInfo['expires_in']) && (int)$tInfo['expires_in'] <= 0)) {
                Response::unauthorized('Invalid or expired Google access token.');
            }
            if (!empty($allowedAudiences)) {
                $aud = $tInfo['aud'] ?? '';
                $azp = $tInfo['azp'] ?? '';
                if (!empty($aud) && !in_array($aud, $allowedAudiences, true) && !in_array($azp, $allowedAudiences, true)) {
                    Response::unauthorized('Google access token client mismatch.');
                }
            }

            // 2. Fetch user profile from Google UserInfo
            $uInfo = self::httpGetJson('https://www.googleapis.com/oauth2/v3/userinfo', [
                'Authorization: Bearer ' . $accessToken,
            ]);
            if (!$uInfo || empty($uInfo['sub']) || empty($uInfo['email'])) {
                Response::unauthorized('Unable to retrieve Google profile from access token.');
            }
            $emailVerified = $uInfo['email_verified'] ?? true;
            if ($emailVerified === false || $emailVerified === 'false') {
                Response::unauthorized('Google account email is not verified.');
            }
            return [
                'sub' => (string)$uInfo['sub'],
                'email' => strtolower(trim((string)$uInfo['email'])),
                'name' => trim((string)($uInfo['name'] ?? '')),
                'avatar_url' => trim((string)($uInfo['picture'] ?? '')),
            ];
        }

        Response::error('Google id_token or access_token is required.', 422);
        return [];
    }

    public static function googleLogin(array $input): void {
        $idToken = trim((string)($input['id_token'] ?? ''));
        $accessToken = trim((string)($input['access_token'] ?? ''));

        $googleProfile = self::verifyGoogleTokens($idToken, $accessToken);
        $googleId = $googleProfile['sub'];
        $email = $googleProfile['email'];
        $googleName = $googleProfile['name'];
        $avatarUrl = $googleProfile['avatar_url'];

        if (empty($googleName)) {
            $parts = explode('@', $email);
            $googleName = !empty($parts[0]) ? $parts[0] : 'Player';
        }

        $pdo = Database::getConnection();
        SchemaMigrator::ensureGoogleAuthColumns($pdo);

        // Look up by Google ID first, then email (seamless account linking)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1");
        $stmt->execute([$googleId, $email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            if (!empty($existingUser['is_banned'])) {
                Response::forbidden('Your account has been suspended. Please contact administrator.');
            }

            $token = $existingUser['api_token'];
            if (empty($token)) {
                $token = bin2hex(random_bytes(32));
            }

            $player = self::fetchPlayerForUser($pdo, $existingUser);
            $wasFirstGoogleAppLogin = ($player === null);

            $newAvatar = !empty($avatarUrl) ? $avatarUrl : ($existingUser['avatar_url'] ?? null);
            if ($wasFirstGoogleAppLogin) {
                $upd = $pdo->prepare("UPDATE users SET google_id = ?, auth_provider = 'google', profile_completed = 0, avatar_url = ?, api_token = ? WHERE id = ?");
                $upd->execute([$googleId, $newAvatar, $token, $existingUser['id']]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET google_id = COALESCE(google_id, ?), auth_provider = 'google', avatar_url = ?, api_token = ? WHERE id = ?");
                $upd->execute([$googleId, $newAvatar, $token, $existingUser['id']]);
            }

            // Ensure every authenticated user has a verified player record visible in Admin Dashboard
            if ($player === null) {
                $pName = !empty($existingUser['name']) && $existingUser['name'] !== 'Administrator'
                    ? $existingUser['name']
                    : $googleName;
                $stmtP = $pdo->prepare("INSERT INTO players (id, user_code, linked_user_id, name, is_verified, email, role, batting_style, bowling_style, jersey_number, city)
                    VALUES (?, ?, ?, ?, 1, ?, 'All-Rounder', 'Right-hand bat', 'Right-arm fast-medium', 7, ?)
                    ON DUPLICATE KEY UPDATE linked_user_id = VALUES(linked_user_id), is_verified = 1, email = VALUES(email)");
                $stmtP->execute([
                    $existingUser['uid'],
                    $existingUser['user_code'],
                    $existingUser['id'],
                    $pName,
                    $existingUser['email'],
                    $existingUser['city'] ?? '',
                ]);
            }

            // Reload fresh user row & player
            $stmt->execute([$googleId, $email]);
            $user = $stmt->fetch();
            $player = self::fetchPlayerForUser($pdo, $user);

            Response::success([
                'user' => self::formatUserPayload($user, $player),
                'token' => $token,
                'is_new_user' => $wasFirstGoogleAppLogin,
                'profile_completed' => (bool)($user['profile_completed'] ?? true),
            ], 'Google sign-in successful');
        }

        // First-time Google user: provision account with profile_completed = 0 so app shows After-Login Setup
        $uid = 'usr_' . bin2hex(random_bytes(8));
        $userCode = userCodeForUid($uid);
        $apiToken = bin2hex(random_bytes(32));

        $pdo->beginTransaction();
        try {
            $stmtIns = $pdo->prepare("INSERT INTO users (uid, user_code, name, email, google_id, auth_provider, password_hash, role, profile_completed, avatar_url, api_token)
                VALUES (?, ?, ?, ?, ?, 'google', NULL, 'player', 0, ?, ?)");
            $stmtIns->execute([$uid, $userCode, $googleName, $email, $googleId, $avatarUrl ?: null, $apiToken]);
            $userId = (int)$pdo->lastInsertId();

            // Create linked Verified Player record
            $stmtP = $pdo->prepare("INSERT INTO players (id, user_code, linked_user_id, name, is_verified, email, role, batting_style, bowling_style, jersey_number, city)
                VALUES (?, ?, ?, ?, 1, ?, 'All-Rounder', 'Right-hand bat', 'Right-arm fast-medium', 7, '')
                ON DUPLICATE KEY UPDATE linked_user_id = VALUES(linked_user_id), is_verified = 1, email = VALUES(email)");
            $stmtP->execute([$uid, $userCode, $userId, $googleName, $email]);

            $pdo->commit();

            $stmtReload = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmtReload->execute([$userId]);
            $user = $stmtReload->fetch();
            $player = self::fetchPlayerForUser($pdo, $user);

            Response::success([
                'user' => self::formatUserPayload($user, $player),
                'token' => $apiToken,
                'is_new_user' => true,
                'profile_completed' => false,
            ], 'Account created via Google. Please complete your cricket profile.', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Failed to provision Google account: ' . $e->getMessage(), 500);
        }
    }

    public static function completeOnboarding(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();
        SchemaMigrator::ensureGoogleAuthColumns($pdo);

        $name = trim((string)($input['name'] ?? ''));
        $cricketRole = trim((string)($input['cricket_role'] ?? $input['player_role'] ?? 'All-Rounder'));
        $battingStyle = trim((string)($input['batting_style'] ?? 'Right-hand bat'));
        $bowlingStyle = trim((string)($input['bowling_style'] ?? 'Right-arm fast-medium'));
        $jerseyNumber = isset($input['jersey_number']) ? (int)$input['jersey_number'] : 7;
        $city = trim((string)($input['city'] ?? ''));
        $contact = trim((string)($input['contact'] ?? $user['contact'] ?? ''));

        if (mb_strlen($name) < 2) {
            Response::error('Please enter your full name (at least 2 characters).', 422);
        }
        $allowedRoles = ['Batter', 'Bowler', 'All-Rounder', 'Wicket-Keeper', 'Scorer'];
        if (!in_array($cricketRole, $allowedRoles, true)) {
            $cricketRole = 'All-Rounder';
        }
        if ($jerseyNumber < 0 || $jerseyNumber > 999) {
            $jerseyNumber = 7;
        }

        $pdo->beginTransaction();
        try {
            $stmtU = $pdo->prepare("UPDATE users SET name = ?, city = ?, contact = ?, profile_completed = 1 WHERE id = ?");
            $stmtU->execute([$name, $city, $contact, $user['id']]);

            $stmtP = $pdo->prepare("INSERT INTO players (id, user_code, linked_user_id, name, is_verified, email, role, batting_style, bowling_style, jersey_number, city)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    linked_user_id = VALUES(linked_user_id),
                    name = VALUES(name),
                    is_verified = 1,
                    role = VALUES(role),
                    batting_style = VALUES(batting_style),
                    bowling_style = VALUES(bowling_style),
                    jersey_number = VALUES(jersey_number),
                    city = VALUES(city)");
            $stmtP->execute([
                $user['uid'],
                $user['user_code'],
                $user['id'],
                $name,
                $user['email'],
                $cricketRole,
                $battingStyle,
                $bowlingStyle,
                $jerseyNumber,
                $city,
            ]);

            $pdo->commit();

            $stmtReload = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmtReload->execute([$user['id']]);
            $freshUser = $stmtReload->fetch();
            $player = self::fetchPlayerForUser($pdo, $freshUser);

            Response::success([
                'user' => self::formatUserPayload($freshUser, $player),
                'profile_completed' => true,
            ], 'Cricket profile setup completed.');
        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Failed to complete onboarding: ' . $e->getMessage(), 500);
        }
    }

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
        SchemaMigrator::ensureGoogleAuthColumns($pdo);

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::error('This email is already registered. Please log in with Google.', 409);
        }

        $uid = 'usr_' . bin2hex(random_bytes(8));
        $userCode = userCodeForUid($uid);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $apiToken = bin2hex(random_bytes(32));

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO users (uid, user_code, name, email, auth_provider, password_hash, contact, city, api_token, role, profile_completed) VALUES (?, ?, ?, ?, 'email', ?, ?, ?, ?, 'player', 1)");
            $stmt->execute([$uid, $userCode, $name, $email, $passwordHash, $contact, $city, $apiToken]);
            $userId = $pdo->lastInsertId();

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
                    'auth_provider' => 'email',
                    'is_verified' => true,
                    'profile_completed' => true,
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
        SchemaMigrator::ensureGoogleAuthColumns($pdo);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid email or password. If you signed up with Google, please continue with Google.', 401);
        }

        if ($user['is_banned']) {
            Response::forbidden('Your account has been suspended. Please contact administrator.');
        }

        $token = $user['api_token'];
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            $update = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
            $update->execute([$token, $user['id']]);
        }

        $player = self::fetchPlayerForUser($pdo, $user);

        Response::success([
            'user' => self::formatUserPayload($user, $player),
            'token' => $token
        ], 'Login successful');
    }

    public static function me(): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();
        SchemaMigrator::ensureGoogleAuthColumns($pdo);

        $player = self::fetchPlayerForUser($pdo, $user);

        Response::success([
            'user' => self::formatUserPayload($user, $player),
        ]);
    }

    public static function updateProfile(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();
        SchemaMigrator::ensureGoogleAuthColumns($pdo);

        $name = trim($input['name'] ?? $user['name']);
        $city = trim($input['city'] ?? $user['city']);
        $contact = trim($input['contact'] ?? $user['contact']);
        $avatarUrl = trim($input['avatar_url'] ?? $user['avatar_url']);
        $cricketRole = trim($input['cricket_role'] ?? $input['player_role'] ?? '');
        $battingStyle = trim($input['batting_style'] ?? '');
        $bowlingStyle = trim($input['bowling_style'] ?? '');
        $jerseyNumber = isset($input['jersey_number']) ? (int)$input['jersey_number'] : null;

        $stmt = $pdo->prepare("UPDATE users SET name = ?, city = ?, contact = ?, avatar_url = ? WHERE id = ?");
        $stmt->execute([$name, $city, $contact, $avatarUrl, $user['id']]);

        // Keep player record in sync
        $existingP = self::fetchPlayerForUser($pdo, $user);
        $stmtP = $pdo->prepare("UPDATE players SET name = ?, city = ?, role = ?, batting_style = ?, bowling_style = ?, jersey_number = ? WHERE linked_user_id = ? OR id = ?");
        $stmtP->execute([
            $name,
            $city,
            $cricketRole ?: ($existingP['role'] ?? 'All-Rounder'),
            $battingStyle ?: ($existingP['batting_style'] ?? 'Right-hand bat'),
            $bowlingStyle ?: ($existingP['bowling_style'] ?? 'Right-arm fast-medium'),
            $jerseyNumber ?? ($existingP['jersey_number'] ?? 7),
            $user['id'],
            $user['uid'],
        ]);

        Response::success(null, 'Profile updated successfully');
    }
}
