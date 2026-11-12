<?php
// 60Score / STUMPS Backend - TeamController
// Handles teams, squads, and player associations.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/user_code.php';
require_once __DIR__ . '/../middleware/auth.php';

class TeamController {

    public static function list(): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM teams ORDER BY name ASC LIMIT 100");
        $teams = $stmt->fetchAll();

        // Attach squad players and deterministic team_code
        foreach ($teams as &$t) {
            $t['team_code'] = teamCodeForId($t['id']);
            $stmtP = $pdo->prepare("SELECT p.id, p.user_code, p.name, p.is_verified, p.role, tp.jersey_no 
                FROM team_players tp 
                JOIN players p ON tp.player_id = p.id 
                WHERE tp.team_id = ?");
            $stmtP->execute([$t['id']]);
            $t['players'] = $stmtP->fetchAll();
        }

        Response::success($teams);
    }

    public static function get(string $id): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM teams WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $team = $stmt->fetch();

        // Check if searched by team_code if not found by primary id
        if (!$team && str_starts_with(strtoupper($id), 'TM-')) {
            $all = $pdo->query("SELECT id FROM teams")->fetchAll();
            foreach ($all as $row) {
                if (strtoupper(teamCodeForId($row['id'])) === strtoupper($id)) {
                    $stmt = $pdo->prepare("SELECT * FROM teams WHERE id = ? LIMIT 1");
                    $stmt->execute([$row['id']]);
                    $team = $stmt->fetch();
                    break;
                }
            }
        }

        if (!$team) {
            Response::notFound('Team not found');
        }

        $team['team_code'] = teamCodeForId($team['id']);
        $stmtP = $pdo->prepare("SELECT p.id, p.user_code, p.name, p.is_verified, p.role, tp.jersey_no 
            FROM team_players tp 
            JOIN players p ON tp.player_id = p.id 
            WHERE tp.team_id = ?");
        $stmtP->execute([$team['id']]);
        $team['players'] = $stmtP->fetchAll();

        Response::success($team);
    }

    public static function search(string $query): void {
        $pdo = Database::getConnection();
        $q = trim($query);
        if (empty($q)) {
            self::list();
            return;
        }

        // Search by team name, short_name or ID
        $stmt = $pdo->prepare("SELECT * FROM teams WHERE name LIKE ? OR short_name LIKE ? OR id LIKE ? ORDER BY name ASC LIMIT 30");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $like]);
        $teams = $stmt->fetchAll();

        // Also check if query matches a deterministic team_code
        $all = $pdo->query("SELECT id FROM teams LIMIT 200")->fetchAll();
        $matchedIds = array_column($teams, 'id');
        foreach ($all as $row) {
            if (in_array($row['id'], $matchedIds)) continue;
            $code = teamCodeForId($row['id']);
            if (stripos($code, $q) !== false) {
                $stmtExtra = $pdo->prepare("SELECT * FROM teams WHERE id = ? LIMIT 1");
                $stmtExtra->execute([$row['id']]);
                $t = $stmtExtra->fetch();
                if ($t) {
                    $teams[] = $t;
                    $matchedIds[] = $t['id'];
                }
            }
        }

        foreach ($teams as &$t) {
            $t['team_code'] = teamCodeForId($t['id']);
            $stmtP = $pdo->prepare("SELECT p.id, p.user_code, p.name, p.is_verified, p.role, tp.jersey_no 
                FROM team_players tp 
                JOIN players p ON tp.player_id = p.id 
                WHERE tp.team_id = ?");
            $stmtP->execute([$t['id']]);
            $t['players'] = $stmtP->fetchAll();
        }

        Response::success($teams);
    }

    public static function create(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $name = trim($input['name'] ?? '');
        $shortName = strtoupper(trim($input['short_name'] ?? ''));
        $colorHex = trim($input['color_hex'] ?? '#00261B');
        $logoBase64 = $input['logo_base64'] ?? null;
        $id = trim($input['id'] ?? '') ?: ('team_' . bin2hex(random_bytes(6)));

        if (empty($name)) {
            Response::error('Team name is required', 422);
        }
        if (empty($shortName)) {
            $shortName = substr($name, 0, 3);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("INSERT INTO teams (id, name, short_name, color_hex, logo_base64, creator_id) 
            VALUES (?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name), color_hex = VALUES(color_hex)");
        $stmt->execute([$id, $name, $shortName, $colorHex, $logoBase64, $user['uid']]);

        // Add squad players if provided
        if (!empty($input['players']) && is_array($input['players'])) {
            $stmtTP = $pdo->prepare("INSERT IGNORE INTO team_players (team_id, player_id, jersey_no) VALUES (?, ?, ?)");
            foreach ($input['players'] as $p) {
                $pid = is_array($p) ? ($p['id'] ?? '') : $p;
                $jno = is_array($p) ? ($p['jersey_no'] ?? null) : null;
                if (!empty($pid)) {
                    $stmtTP->execute([$id, $pid, $jno]);
                }
            }
        }

        Response::success(['id' => $id, 'name' => $name], 'Team saved successfully', 201);
    }
}
