<?php
// 60Score / STUMPS Backend - SyncController
// 100% Offline-First Batch Sync Engine.
// Receives batched local changes from Flutter mobile clients when network is available.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/user_code.php';
require_once __DIR__ . '/../middleware/auth.php';

class SyncController {

    public static function batchSync(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();

        $matches = $input['matches'] ?? [];
        $players = $input['players'] ?? [];
        $teams = $input['teams'] ?? [];
        $tournaments = $input['tournaments'] ?? [];

        $syncedMatchIds = [];
        $syncedPlayerIds = [];
        $syncedTeamIds = [];
        $syncedTournamentIds = [];
        $errors = [];

        $pdo->beginTransaction();
        try {
            // 1. Sync Players (Manual unverified or squad players)
            if (!empty($players) && is_array($players)) {
                $stmtPlayer = $pdo->prepare("INSERT INTO players 
                    (id, user_code, name, is_verified, email, role, batting_style, bowling_style, city)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name),
                        role = VALUES(role),
                        city = VALUES(city)");

                foreach ($players as $p) {
                    $pid = trim($p['id'] ?? '');
                    if (empty($pid)) continue;

                    $pCode = !empty($p['userCode']) ? $p['userCode'] : userCodeForUid($pid);
                    $pName = trim($p['name'] ?? 'Player');
                    $isVer = !empty($p['isVerified']) ? 1 : 0;
                    $pEmail = $p['email'] ?? null;
                    $pRole = $p['role'] ?? 'All-Rounder';
                    $pBat = $p['battingStyle'] ?? 'Right-hand';
                    $pBowl = $p['bowlingStyle'] ?? 'Right-arm medium';
                    $pCity = $p['city'] ?? '';

                    $stmtPlayer->execute([$pid, $pCode, $pName, $isVer, $pEmail, $pRole, $pBat, $pBowl, $pCity]);
                    $syncedPlayerIds[] = $pid;
                }
            }

            // 2. Sync Teams
            if (!empty($teams) && is_array($teams)) {
                $stmtTeam = $pdo->prepare("INSERT INTO teams (id, name, short_name, color_hex, creator_id)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name)");

                foreach ($teams as $t) {
                    $tid = trim($t['id'] ?? '');
                    if (empty($tid)) continue;
                    $tName = trim($t['name'] ?? 'Team');
                    $tShort = trim($t['shortName'] ?? substr($tName, 0, 3));
                    $tColor = $t['color'] ?? '#00261B';

                    $stmtTeam->execute([$tid, $tName, $tShort, $tColor, $user['uid']]);
                    $syncedTeamIds[] = $tid;
                }
            }

            // 3. Sync Matches (With strict Scorer Ownership check)
            if (!empty($matches) && is_array($matches)) {
                $stmtCheckMatch = $pdo->prepare("SELECT creator_id, updated_at_epoch FROM matches WHERE id = ? LIMIT 1");
                $stmtUpsertMatch = $pdo->prepare("INSERT INTO matches 
                    (id, tournament_id, creator_id, creator_code, creator_name, team_a_id, team_b_id, team_a_name, team_b_name, overs_limit, balls_per_over, venue, date_iso, toss_winner_id, toss_decision, status, result_text, match_title, match_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        status = VALUES(status), 
                        result_text = VALUES(result_text), 
                        match_payload = VALUES(match_payload), 
                        updated_at_epoch = VALUES(updated_at_epoch)");

                foreach ($matches as $m) {
                    $mid = trim($m['id'] ?? '');
                    if (empty($mid)) continue;

                    $epoch = (int)($m['updatedAtEpoch'] ?? round(microtime(true) * 1000));

                    // Check existing match ownership
                    $stmtCheckMatch->execute([$mid]);
                    $existing = $stmtCheckMatch->fetch();

                    if ($existing) {
                        // SCORER CHECK: If match exists, ONLY creator can update it
                        if ($existing['creator_id'] !== $user['uid'] && $user['role'] !== 'admin') {
                            $errors[] = "Match {$mid} skipped: You are not the creator.";
                            continue;
                        }

                        // Last-Write-Wins check
                        if ($existing['updated_at_epoch'] > $epoch) {
                            // Server already has a newer copy, acknowledge without overwriting
                            $syncedMatchIds[] = $mid;
                            continue;
                        }
                    }

                    $tournId = !empty($m['tournamentId']) ? $m['tournamentId'] : null;
                    $teamAId = $m['teamAId'] ?? 'teamA';
                    $teamBId = $m['teamBId'] ?? 'teamB';
                    $teamAName = $m['teamAName'] ?? 'Team A';
                    $teamBName = $m['teamBName'] ?? 'Team B';
                    $overs = (int)($m['oversLimit'] ?? 20);
                    $balls = (int)($m['ballsPerOver'] ?? 6);
                    $venue = $m['venue'] ?? '';
                    $dateIso = $m['dateIso'] ?? '';
                    $tossWin = $m['tossWinnerId'] ?? '';
                    $tossDec = $m['tossDecision'] ?? 'bat';
                    $status = $m['status'] ?? 'live';
                    $resText = $m['resultText'] ?? '';
                    $mTitle = $m['matchTitle'] ?? '';
                    $payloadStr = json_encode($m, JSON_UNESCAPED_UNICODE);

                    $stmtUpsertMatch->execute([
                        $mid, $tournId, $user['uid'], $user['user_code'], $user['name'],
                        $teamAId, $teamBId, $teamAName, $teamBName,
                        $overs, $balls, $venue, $dateIso,
                        $tossWin, $tossDec, $status, $resText, $mTitle,
                        $payloadStr, $epoch
                    ]);

                    $syncedMatchIds[] = $mid;
                }
            }

            // 4. Sync Tournaments
            if (!empty($tournaments) && is_array($tournaments)) {
                $stmtTourn = $pdo->prepare("INSERT INTO tournaments 
                    (id, name, owner_uid, venue, format, status, tournament_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), 
                        tournament_payload = VALUES(tournament_payload), 
                        updated_at_epoch = VALUES(updated_at_epoch)");

                foreach ($tournaments as $t) {
                    $tid = trim($t['id'] ?? '');
                    if (empty($tid)) continue;

                    $epoch = (int)($t['updatedAtEpoch'] ?? round(microtime(true) * 1000));
                    $tName = $t['name'] ?? 'Tournament';
                    $tVenue = $t['venue'] ?? '';
                    $tFormat = $t['format'] ?? 'Round Robin';
                    $tStatus = $t['status'] ?? 'draft';
                    $tPayload = json_encode($t, JSON_UNESCAPED_UNICODE);

                    $stmtTourn->execute([$tid, $tName, $user['uid'], $tVenue, $tFormat, $tStatus, $tPayload, $epoch]);
                    $syncedTournamentIds[] = $tid;
                }
            }

            $pdo->commit();

            Response::success([
                'synced_matches' => $syncedMatchIds,
                'synced_players' => $syncedPlayerIds,
                'synced_teams' => $syncedTeamIds,
                'synced_tournaments' => $syncedTournamentIds,
                'server_time' => round(microtime(true) * 1000),
                'warnings' => $errors
            ], 'Offline batch sync processed successfully');

        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Sync failed: ' . $e->getMessage(), 500);
        }
    }
}
