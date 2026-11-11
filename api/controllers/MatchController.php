<?php
// 60Score / STUMPS Backend - MatchController
// Manages match creation, live scoreboard, scorecard telemetry, and strictly enforces creator-only scoring permissions.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

class MatchController {

    public static function listLive(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT id, match_title, team_a_name, team_b_name, overs_limit, status, venue, date_iso, result_text, creator_name, updated_at 
            FROM matches 
            WHERE status = 'live' 
            ORDER BY updated_at DESC LIMIT 50");
        $liveMatches = $stmt->fetchAll();

        Response::success($liveMatches);
    }

    public static function listAll(): void {
        $pdo = Database::getConnection();
        $status = $_GET['status'] ?? null;
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 30)));

        if ($status) {
            $stmt = $pdo->prepare("SELECT id, match_title, team_a_name, team_b_name, overs_limit, status, venue, date_iso, result_text, creator_name, updated_at 
                FROM matches WHERE status = ? ORDER BY updated_at DESC LIMIT ?");
            $stmt->execute([$status, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT id, match_title, team_a_name, team_b_name, overs_limit, status, venue, date_iso, result_text, creator_name, updated_at 
                FROM matches ORDER BY updated_at DESC LIMIT ?");
            $stmt->execute([$limit]);
        }

        Response::success($stmt->fetchAll());
    }

    public static function getDetail(string $id): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $match = $stmt->fetch();

        if (!$match) {
            Response::notFound('Match not found');
        }

        // Decode payload JSON for rich client hydration
        $payload = json_decode($match['match_payload'] ?? '{}', true);

        // Check if viewing user is the creator
        $currentUser = AuthMiddleware::authenticate(false);
        $isCreator = $currentUser && ($currentUser['uid'] === $match['creator_id'] || $currentUser['role'] === 'admin');

        Response::success([
            'id' => $match['id'],
            'tournament_id' => $match['tournament_id'],
            'creator_id' => $match['creator_id'],
            'creator_name' => $match['creator_name'],
            'creator_code' => $match['creator_code'],
            'is_current_user_creator' => $isCreator,
            'team_a_id' => $match['team_a_id'],
            'team_b_id' => $match['team_b_id'],
            'team_a_name' => $match['team_a_name'],
            'team_b_name' => $match['team_b_name'],
            'overs_limit' => (int)$match['overs_limit'],
            'balls_per_over' => (int)$match['balls_per_over'],
            'venue' => $match['venue'],
            'date_iso' => $match['date_iso'],
            'status' => $match['status'],
            'result_text' => $match['result_text'],
            'man_of_match_id' => $match['man_of_match_id'],
            'payload' => $payload,
            'updated_at' => $match['updated_at']
        ]);
    }

    public static function create(array $input): void {
        $user = AuthMiddleware::authenticate(true);

        $id = trim($input['id'] ?? '') ?: ('m_' . bin2hex(random_bytes(8)));
        $teamAId = trim($input['team_a_id'] ?? '');
        $teamBId = trim($input['team_b_id'] ?? '');
        $teamAName = trim($input['team_a_name'] ?? 'Team A');
        $teamBName = trim($input['team_b_name'] ?? 'Team B');
        $oversLimit = (int)($input['overs_limit'] ?? 20);
        $ballsPerOver = (int)($input['balls_per_over'] ?? 6);
        $venue = trim($input['venue'] ?? '');
        $dateIso = trim($input['date_iso'] ?? date('c'));
        $tossWinnerId = trim($input['toss_winner_id'] ?? '');
        $tossDecision = trim($input['toss_decision'] ?? 'bat');
        $tournamentId = !empty($input['tournament_id']) ? trim($input['tournament_id']) : null;
        $matchTitle = trim($input['match_title'] ?? '');
        $ballType = trim($input['ball_type'] ?? 'Tennis Ball');

        $payload = json_encode($input['payload'] ?? $input, JSON_UNESCAPED_UNICODE);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO matches 
            (id, tournament_id, creator_id, creator_code, creator_name, team_a_id, team_b_id, team_a_name, team_b_name, overs_limit, balls_per_over, venue, date_iso, toss_winner_id, toss_decision, status, match_title, ball_type, match_payload, updated_at_epoch)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'live', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                match_payload = VALUES(match_payload), 
                updated_at_epoch = VALUES(updated_at_epoch)");

        $epoch = round(microtime(true) * 1000);
        $stmt->execute([
            $id, $tournamentId, $user['uid'], $user['user_code'], $user['name'],
            $teamAId, $teamBId, $teamAName, $teamBName,
            $oversLimit, $ballsPerOver, $venue, $dateIso,
            $tossWinnerId, $tossDecision,
            $matchTitle, $ballType, $payload, $epoch
        ]);

        Response::success([
            'id' => $id,
            'creator_id' => $user['uid'],
            'creator_name' => $user['name']
        ], 'Match created successfully', 201);
    }

    public static function update(string $id, array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();

        // 1. Verify Match Existence & Creator Rights
        $stmt = $pdo->prepare("SELECT creator_id, creator_name, status FROM matches WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::notFound('Match not found');
        }

        // STRICT SCORER AUTHORIZATION GUARD
        if ($existing['creator_id'] !== $user['uid'] && $user['role'] !== 'admin') {
            Response::forbidden("Unauthorized: Only the match creator ({$existing['creator_name']}) can score or update this match.");
        }

        $status = $input['status'] ?? $existing['status'];
        $resultText = $input['result_text'] ?? '';
        $manOfMatchId = $input['man_of_match_id'] ?? null;
        $payload = json_encode($input['payload'] ?? $input, JSON_UNESCAPED_UNICODE);
        $epoch = (int)($input['updated_at_epoch'] ?? round(microtime(true) * 1000));

        $update = $pdo->prepare("UPDATE matches SET 
            status = ?, 
            result_text = ?, 
            man_of_match_id = ?, 
            match_payload = ?, 
            updated_at_epoch = ? 
            WHERE id = ?");
        $update->execute([$status, $resultText, $manOfMatchId, $payload, $epoch, $id]);

        Response::success(['id' => $id, 'status' => $status], 'Match updated successfully');
    }
}
