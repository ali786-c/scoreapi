<?php
// 60Score / STUMPS Backend - MatchController
// Manages match creation, live scoreboard feed, scorecard telemetry, and strictly enforces creator-only scoring permissions.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

class MatchController {

    private static function summarizeMatchRow(array $row): array {
        $payload = json_decode($row['match_payload'] ?? '{}', true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $ballsPerOver = max(1, (int)($row['balls_per_over'] ?? $payload['ballsPerOver'] ?? 6));
        $inningsSummary = [];
        if (!empty($payload['innings']) && is_array($payload['innings'])) {
            foreach ($payload['innings'] as $inn) {
                if (!is_array($inn)) continue;
                $runs = (int)($inn['totalRuns'] ?? 0);
                $wkts = (int)($inn['wickets'] ?? 0);
                $legalBalls = (int)($inn['legalBalls'] ?? 0);
                $oversText = intdiv($legalBalls, $ballsPerOver) . '.' . ($legalBalls % $ballsPerOver);
                $crr = $legalBalls > 0 ? round(($runs * $ballsPerOver) / $legalBalls, 2) : 0.0;
                $inningsSummary[] = [
                    'batting_team_id' => (string)($inn['battingTeamId'] ?? ''),
                    'total_runs' => $runs,
                    'wickets' => $wkts,
                    'legal_balls' => $legalBalls,
                    'overs_text' => $oversText,
                    'crr' => $crr,
                    'target' => (int)($inn['target'] ?? 0),
                ];
            }
        }

        return [
            'id' => $row['id'],
            'tournament_id' => $row['tournament_id'] ?? null,
            'creator_id' => $row['creator_id'] ?? '',
            'creator_code' => $row['creator_code'] ?? '',
            'creator_name' => $row['creator_name'] ?? '',
            'team_a_id' => $row['team_a_id'] ?? '',
            'team_b_id' => $row['team_b_id'] ?? '',
            'team_a_name' => $row['team_a_name'] ?? 'Team A',
            'team_b_name' => $row['team_b_name'] ?? 'Team B',
            'match_title' => $row['match_title'] ?? '',
            'overs_limit' => (int)($row['overs_limit'] ?? 20),
            'balls_per_over' => $ballsPerOver,
            'venue' => $row['venue'] ?? '',
            'date_iso' => $row['date_iso'] ?? '',
            'status' => $row['status'] ?? 'live',
            'stage' => $row['stage'] ?? 'league',
            'result_text' => $row['result_text'] ?? '',
            'ball_type' => $row['ball_type'] ?? 'Tennis Ball',
            'innings_summary' => $inningsSummary,
            'updated_at_epoch' => (int)($row['updated_at_epoch'] ?? 0),
            'updated_at' => $row['updated_at'] ?? '',
        ];
    }

    private static function fetchTeamWithSquad(PDO $pdo, string $teamId, string $fallbackName): array {
        $stmtT = $pdo->prepare("SELECT * FROM teams WHERE id = ? LIMIT 1");
        $stmtT->execute([$teamId]);
        $tRow = $stmtT->fetch();

        if ($tRow && !empty($tRow['team_payload'])) {
            $decoded = json_decode($tRow['team_payload'], true);
            if (is_array($decoded) && !empty($decoded['id'])) {
                return $decoded;
            }
        }

        $stmtTP = $pdo->prepare("SELECT p.*, tp.jersey_no as tp_jersey, tp.role as tp_role, tp.is_captain, tp.is_vice_captain, tp.is_keeper
            FROM team_players tp
            JOIN players p ON tp.player_id = p.id
            WHERE tp.team_id = ?");
        $stmtTP->execute([$teamId]);
        $squad = [];
        foreach ($stmtTP->fetchAll() as $sp) {
            $squad[] = [
                'id' => $sp['id'],
                'name' => $sp['name'],
                'jerseyNo' => (int)($sp['tp_jersey'] ?? $sp['jersey_number'] ?? 0),
                'role' => $sp['tp_role'] ?? $sp['role'] ?? 'Batter',
                'battingStyle' => $sp['batting_style'] ?? 'Right-hand',
                'bowlingStyle' => $sp['bowling_style'] ?? 'None',
                'isCaptain' => (bool)$sp['is_captain'],
                'isViceCaptain' => (bool)$sp['is_vice_captain'],
                'isKeeper' => (bool)$sp['is_keeper'],
                'photoBase64' => $sp['photo_base64'] ?? '',
                'isVerified' => (bool)$sp['is_verified'],
                'userCode' => $sp['user_code'],
                'city' => $sp['city'] ?? '',
            ];
        }

        return [
            'id' => $teamId,
            'name' => $tRow['name'] ?? $fallbackName,
            'players' => $squad,
        ];
    }

    public static function feed(): void {
        $pdo = Database::getConnection();
        $limit = min(30, max(5, (int)($_GET['limit'] ?? 15)));

        $stmtLive = $pdo->prepare("SELECT * FROM matches WHERE status = 'live' ORDER BY updated_at_epoch DESC, updated_at DESC LIMIT ?");
        $stmtLive->execute([$limit]);
        $live = array_map([self::class, 'summarizeMatchRow'], $stmtLive->fetchAll());

        $stmtRecent = $pdo->prepare("SELECT * FROM matches WHERE status IN ('completed', 'noResult') ORDER BY updated_at_epoch DESC, updated_at DESC LIMIT ?");
        $stmtRecent->execute([$limit]);
        $recent = array_map([self::class, 'summarizeMatchRow'], $stmtRecent->fetchAll());

        $liveCount = (int)$pdo->query("SELECT COUNT(*) FROM matches WHERE status = 'live'")->fetchColumn();

        Response::success([
            'live' => $live,
            'recent' => $recent,
            'live_count' => $liveCount,
            'server_time' => (int)round(microtime(true) * 1000),
        ]);
    }

    public static function listLive(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM matches WHERE status = 'live' ORDER BY updated_at_epoch DESC, updated_at DESC LIMIT 50");
        $liveMatches = array_map([self::class, 'summarizeMatchRow'], $stmt->fetchAll());

        Response::success($liveMatches);
    }

    public static function listAll(): void {
        $pdo = Database::getConnection();
        $status = $_GET['status'] ?? null;
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 30)));

        if ($status) {
            $stmt = $pdo->prepare("SELECT * FROM matches WHERE status = ? ORDER BY updated_at_epoch DESC, updated_at DESC LIMIT ?");
            $stmt->execute([$status, $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM matches ORDER BY updated_at_epoch DESC, updated_at DESC LIMIT ?");
            $stmt->execute([$limit]);
        }

        Response::success(array_map([self::class, 'summarizeMatchRow'], $stmt->fetchAll()));
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
        if (!is_array($payload)) {
            $payload = [];
        }

        // Attach team & squad records so spectators see real team and player names
        $teams = [
            self::fetchTeamWithSquad($pdo, (string)$match['team_a_id'], (string)$match['team_a_name']),
            self::fetchTeamWithSquad($pdo, (string)$match['team_b_id'], (string)$match['team_b_name']),
        ];

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
            'teams' => $teams,
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
