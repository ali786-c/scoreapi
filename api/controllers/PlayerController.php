<?php
// 60Score / STUMPS Backend - PlayerController
// Manages Verified and Unverified players, deterministic user codes, claims, and career telemetry.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/user_code.php';
require_once __DIR__ . '/../middleware/auth.php';

class PlayerController {

    public static function getProfile(string $identifier): void {
        $identifier = trim($identifier);
        if (empty($identifier)) {
            Response::error('Player ID or User Code required', 422);
        }

        $pdo = Database::getConnection();

        // Check by player ID or by 60S- user_code
        $stmt = $pdo->prepare("SELECT p.*, u.email as linked_email FROM players p 
            LEFT JOIN users u ON p.linked_user_id = u.id 
            WHERE p.id = ? OR p.user_code = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $player = $stmt->fetch();

        if (!$player) {
            // If player not registered in DB yet, synthesize an unverified profile matching deterministic rules
            $userCode = userCodeForUid($identifier);
            Response::success([
                'id' => $identifier,
                'user_code' => $userCode,
                'name' => 'Player ' . substr($userCode, -4),
                'is_verified' => false,
                'email' => null,
                'role' => 'All-Rounder',
                'batting_style' => 'Right-hand',
                'bowling_style' => 'Right-arm medium',
                'city' => '',
                'career_stats' => [
                    'matches' => 0,
                    'runs' => 0,
                    'wickets' => 0,
                    'high_score' => 0
                ]
            ]);
            return;
        }

        $careerStats = json_decode($player['career_stats_json'] ?? '{}', true);

        Response::success([
            'id' => $player['id'],
            'user_code' => $player['user_code'],
            'name' => $player['name'],
            'is_verified' => (bool)$player['is_verified'],
            'email' => $player['linked_email'] ?? $player['email'],
            'role' => $player['role'],
            'batting_style' => $player['batting_style'],
            'bowling_style' => $player['bowling_style'],
            'city' => $player['city'],
            'photo_base64' => $player['photo_base64'],
            'career_stats' => $careerStats
        ]);
    }

    public static function claimProfile(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $playerId = trim($input['player_id'] ?? '');

        if (empty($playerId)) {
            Response::error('player_id is required to claim a profile', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ? LIMIT 1");
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();

        if ($player && $player['is_verified'] && !empty($player['linked_user_id']) && $player['linked_user_id'] != $user['id']) {
            Response::error('This player profile is already claimed and verified by another account.', 409);
        }

        // Link the player record to the authenticated user
        $stmt = $pdo->prepare("UPDATE players SET 
            linked_user_id = ?, 
            is_verified = 1, 
            email = ? 
            WHERE id = ?");
        $stmt->execute([$user['id'], $user['email'], $playerId]);

        Response::success([
            'player_id' => $playerId,
            'is_verified' => true,
            'linked_email' => $user['email']
        ], 'Player profile claimed successfully! It is now verified.');
    }

    public static function search(string $query): void {
        $query = trim($query);
        if (strlen($query) < 2) {
            Response::success([], 'Query too short');
        }

        $pdo = Database::getConnection();
        $term = '%' . $query . '%';
        $stmt = $pdo->prepare("SELECT id, user_code, name, is_verified, role, city FROM players 
            WHERE name LIKE ? OR user_code LIKE ? LIMIT 20");
        $stmt->execute([$term, $term]);
        $results = $stmt->fetchAll();

        Response::success($results);
    }
}
