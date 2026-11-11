<?php
// 60Score / STUMPS Backend - TournamentController
// Manages tournaments, public search, publishing, and standings.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

class TournamentController {

    public static function listPublic(): void {
        $pdo = Database::getConnection();
        $q = trim($_GET['q'] ?? '');
        $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));

        if (!empty($q)) {
            $stmt = $pdo->prepare("SELECT id, name, owner_uid, venue, format, status, is_published, updated_at 
                FROM tournaments 
                WHERE is_published = 1 AND name LIKE ? 
                ORDER BY updated_at DESC LIMIT ?");
            $stmt->execute(['%' . $q . '%', $limit]);
        } else {
            $stmt = $pdo->prepare("SELECT id, name, owner_uid, venue, format, status, is_published, updated_at 
                FROM tournaments 
                WHERE is_published = 1 
                ORDER BY updated_at DESC LIMIT ?");
            $stmt->execute([$limit]);
        }

        Response::success($stmt->fetchAll());
    }

    public static function getDetail(string $id): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $tournament = $stmt->fetch();

        if (!$tournament) {
            Response::notFound('Tournament not found');
        }

        $payload = json_decode($tournament['tournament_payload'] ?? '{}', true);

        Response::success([
            'id' => $tournament['id'],
            'name' => $tournament['name'],
            'owner_uid' => $tournament['owner_uid'],
            'venue' => $tournament['venue'],
            'format' => $tournament['format'],
            'status' => $tournament['status'],
            'is_published' => (bool)$tournament['is_published'],
            'logo_base64' => $tournament['logo_base64'],
            'payload' => $payload,
            'updated_at' => $tournament['updated_at']
        ]);
    }

    public static function publish(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $id = trim($input['id'] ?? '');
        $isPublished = !empty($input['is_published']) ? 1 : 0;

        if (empty($id)) {
            Response::error('Tournament ID required', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT owner_uid FROM tournaments WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $t = $stmt->fetch();

        if ($t && $t['owner_uid'] !== $user['uid'] && $user['role'] !== 'admin') {
            Response::forbidden('Only the tournament owner can publish or unpublish.');
        }

        $update = $pdo->prepare("UPDATE tournaments SET is_published = ? WHERE id = ?");
        $update->execute([$isPublished, $id]);

        Response::success(['id' => $id, 'is_published' => (bool)$isPublished], 'Publishing status updated');
    }
}
