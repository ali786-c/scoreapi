<?php
// 60Score / STUMPS Backend - Full 2-Way Offline-First Sync Engine
// Covers ALL 10 app entities & metrics:
// Profile, Players (photos + career stats), Teams + Squads (team_players),
// Matches (ball-by-ball, wagon wheel, DLS, Super Over), Tournaments (points + player overrides),
// Drafts (rounds + picks), Registered Players (draft pool), Manual Stats, and Tombstones.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/user_code.php';
require_once __DIR__ . '/../helpers/schema_migrator.php';
require_once __DIR__ . '/../middleware/auth.php';

class SyncController {

    public static function batchSync(array $input): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();
        SchemaMigrator::ensureSchemaUpToDate($pdo);

        $profile = $input['profile'] ?? null;
        $matches = $input['matches'] ?? [];
        $players = $input['players'] ?? [];
        $teams = $input['teams'] ?? [];
        $tournaments = $input['tournaments'] ?? [];
        $regPlayers = $input['regPlayers'] ?? $input['reg_players'] ?? [];
        $drafts = $input['drafts'] ?? [];
        $manualStats = $input['manualStats'] ?? $input['manual_stats'] ?? [];
        $tombstones = $input['tombstones'] ?? [];

        $syncedMatchIds = [];
        $syncedPlayerIds = [];
        $syncedTeamIds = [];
        $syncedTournamentIds = [];
        $syncedRegPlayerIds = [];
        $syncedDraftIds = [];
        $syncedManualStatIds = [];
        $syncedTombstoneIds = [];
        $errors = [];

        $defaultEpoch = (int)round(microtime(true) * 1000);

        $pdo->beginTransaction();
        try {
            // 0. Sync Active Account Profile
            if (is_array($profile) && !empty($profile['displayName'])) {
                $pName = trim((string)$profile['displayName']);
                $pCity = trim((string)($profile['city'] ?? ''));
                $pContact = trim((string)($profile['contact'] ?? $user['contact'] ?? ''));
                $pRole = trim((string)($profile['role'] ?? 'All-Rounder'));
                $pBat = trim((string)($profile['battingStyle'] ?? 'Right-hand bat'));
                $pBowl = trim((string)($profile['bowlingStyle'] ?? 'Right-arm fast-medium'));
                $pJersey = (int)($profile['jerseyNumber'] ?? 7);

                $pdo->prepare("UPDATE users SET name = ?, city = ?, contact = ? WHERE id = ?")
                    ->execute([$pName, $pCity, $pContact, $user['id']]);
                $pdo->prepare("UPDATE players SET name = ?, role = ?, batting_style = ?, bowling_style = ?, jersey_number = ?, city = ? WHERE linked_user_id = ? OR id = ?")
                    ->execute([$pName, $pRole, $pBat, $pBowl, $pJersey, $pCity, $user['id'], $user['uid']]);
            }

            // 1. Sync Players (Photos, Styles, Jersey, & Computed Career Stats JSON)
            $stmtPlayerUpsert = $pdo->prepare("INSERT INTO players 
                (id, user_code, owner_uid, name, is_verified, email, role, batting_style, bowling_style, jersey_number, city, photo_base64, career_stats_json, updated_at_epoch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    name = VALUES(name),
                    role = VALUES(role),
                    batting_style = VALUES(batting_style),
                    bowling_style = VALUES(bowling_style),
                    jersey_number = VALUES(jersey_number),
                    city = VALUES(city),
                    photo_base64 = COALESCE(NULLIF(VALUES(photo_base64), ''), photo_base64),
                    career_stats_json = COALESCE(VALUES(career_stats_json), career_stats_json),
                    updated_at_epoch = GREATEST(updated_at_epoch, VALUES(updated_at_epoch))");

            $upsertSinglePlayer = function (array $p) use ($stmtPlayerUpsert, $user, $defaultEpoch): string {
                $pid = trim((string)($p['id'] ?? ''));
                if ($pid === '') return '';
                $pCode = !empty($p['userCode']) ? (string)$p['userCode'] : userCodeForUid($pid);
                $pName = trim((string)($p['name'] ?? 'Player'));
                $isVer = !empty($p['isVerified']) ? 1 : 0;
                $pEmail = !empty($p['email']) ? (string)$p['email'] : null;
                $pRole = (string)($p['role'] ?? 'All-Rounder');
                $pBat = (string)($p['battingStyle'] ?? 'Right-hand');
                $pBowl = (string)($p['bowlingStyle'] ?? 'None');
                $pJersey = (int)($p['jerseyNo'] ?? $p['jerseyNumber'] ?? 7);
                $pCity = (string)($p['city'] ?? '');
                $pPhoto = !empty($p['photoBase64']) ? (string)$p['photoBase64'] : null;
                $careerJson = isset($p['careerStats']) && is_array($p['careerStats'])
                    ? json_encode($p['careerStats'], JSON_UNESCAPED_UNICODE)
                    : null;
                $epoch = (int)($p['updatedAtEpoch'] ?? $defaultEpoch);

                $stmtPlayerUpsert->execute([
                    $pid, $pCode, $user['uid'], $pName, $isVer, $pEmail,
                    $pRole, $pBat, $pBowl, $pJersey, $pCity, $pPhoto, $careerJson, $epoch
                ]);
                return $pid;
            };

            if (!empty($players) && is_array($players)) {
                foreach ($players as $p) {
                    if (!is_array($p)) continue;
                    $pid = $upsertSinglePlayer($p);
                    if ($pid !== '') $syncedPlayerIds[] = $pid;
                }
            }

            // 2. Sync Teams & Full Squad Links (team_players)
            if (!empty($teams) && is_array($teams)) {
                $stmtTeam = $pdo->prepare("INSERT INTO teams 
                    (id, name, short_name, color_hex, logo_base64, creator_id, team_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), 
                        short_name = VALUES(short_name),
                        color_hex = VALUES(color_hex),
                        logo_base64 = COALESCE(NULLIF(VALUES(logo_base64), ''), logo_base64),
                        team_payload = VALUES(team_payload),
                        updated_at_epoch = GREATEST(updated_at_epoch, VALUES(updated_at_epoch))");

                $stmtClearTP = $pdo->prepare("DELETE FROM team_players WHERE team_id = ?");
                $stmtInsertTP = $pdo->prepare("INSERT INTO team_players 
                    (team_id, player_id, jersey_no, role, is_captain, is_vice_captain, is_keeper) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        jersey_no = VALUES(jersey_no),
                        role = VALUES(role),
                        is_captain = VALUES(is_captain),
                        is_vice_captain = VALUES(is_vice_captain),
                        is_keeper = VALUES(is_keeper)");

                foreach ($teams as $t) {
                    if (!is_array($t)) continue;
                    $tid = trim((string)($t['id'] ?? ''));
                    if ($tid === '') continue;

                    $tName = trim((string)($t['name'] ?? 'Team'));
                    $tShort = trim((string)($t['shortName'] ?? strtoupper(substr($tName, 0, 3))));
                    $tColor = (string)($t['color'] ?? '#00261B');
                    $tLogo = !empty($t['logoBase64']) ? (string)$t['logoBase64'] : null;
                    $epoch = (int)($t['updatedAtEpoch'] ?? $defaultEpoch);
                    $tPayload = json_encode($t, JSON_UNESCAPED_UNICODE);

                    $stmtTeam->execute([$tid, $tName, $tShort, $tColor, $tLogo, $user['uid'], $tPayload, $epoch]);

                    if (isset($t['players']) && is_array($t['players'])) {
                        $stmtClearTP->execute([$tid]);
                        foreach ($t['players'] as $tp) {
                            if (!is_array($tp)) continue;
                            $pid = $upsertSinglePlayer($tp);
                            if ($pid === '') continue;
                            if (!in_array($pid, $syncedPlayerIds, true)) {
                                $syncedPlayerIds[] = $pid;
                            }
                            $stmtInsertTP->execute([
                                $tid,
                                $pid,
                                (int)($tp['jerseyNo'] ?? 0),
                                (string)($tp['role'] ?? 'Batter'),
                                !empty($tp['isCaptain']) ? 1 : 0,
                                !empty($tp['isViceCaptain']) ? 1 : 0,
                                !empty($tp['isKeeper']) ? 1 : 0,
                            ]);
                        }
                    }

                    $syncedTeamIds[] = $tid;
                }
            }

            // 3. Sync Matches (Ball-by-Ball, Wagon Wheel, DLS, Super Over, Strict Creator Ownership)
            if (!empty($matches) && is_array($matches)) {
                $stmtCheckMatch = $pdo->prepare("SELECT creator_id, updated_at_epoch FROM matches WHERE id = ? LIMIT 1");
                $stmtUpsertMatch = $pdo->prepare("INSERT INTO matches 
                    (id, tournament_id, creator_id, creator_code, creator_name, team_a_id, team_b_id, team_a_name, team_b_name, overs_limit, balls_per_over, total_wickets, last_man_stands, wide_penalty, no_ball_penalty, venue, date_iso, toss_winner_id, toss_decision, status, stage, result_text, man_of_match_id, match_title, ball_type, season, match_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        tournament_id = VALUES(tournament_id),
                        team_a_name = VALUES(team_a_name),
                        team_b_name = VALUES(team_b_name),
                        status = VALUES(status), 
                        stage = VALUES(stage),
                        result_text = VALUES(result_text), 
                        man_of_match_id = VALUES(man_of_match_id),
                        match_title = VALUES(match_title),
                        match_payload = VALUES(match_payload), 
                        updated_at_epoch = VALUES(updated_at_epoch)");

                foreach ($matches as $m) {
                    if (!is_array($m)) continue;
                    $mid = trim((string)($m['id'] ?? ''));
                    if ($mid === '') continue;

                    $epoch = (int)($m['updatedAtEpoch'] ?? $defaultEpoch);

                    $stmtCheckMatch->execute([$mid]);
                    $existing = $stmtCheckMatch->fetch();

                    if ($existing) {
                        if ($existing['creator_id'] !== $user['uid'] && $user['role'] !== 'admin') {
                            $errors[] = "Match {$mid} skipped: You are not the creator.";
                            continue;
                        }
                        if ((int)$existing['updated_at_epoch'] > $epoch) {
                            $syncedMatchIds[] = $mid;
                            continue;
                        }
                    }

                    $tournId = !empty($m['tournamentId']) ? (string)$m['tournamentId'] : null;
                    $teamAId = (string)($m['teamAId'] ?? 'teamA');
                    $teamBId = (string)($m['teamBId'] ?? 'teamB');
                    $teamAName = (string)($m['teamAName'] ?? 'Team A');
                    $teamBName = (string)($m['teamBName'] ?? 'Team B');
                    $overs = (int)($m['oversLimit'] ?? 20);
                    $balls = (int)($m['ballsPerOver'] ?? 6);
                    $totalWickets = (int)($m['totalWickets'] ?? 10);
                    $lastMan = !empty($m['lastManStands']) ? 1 : 0;
                    $widePen = array_key_exists('widePenalty', $m) ? (!empty($m['widePenalty']) ? 1 : 0) : 1;
                    $noBallPen = array_key_exists('noBallPenalty', $m) ? (!empty($m['noBallPenalty']) ? 1 : 0) : 1;
                    $venue = (string)($m['venue'] ?? '');
                    $dateIso = (string)($m['dateIso'] ?? '');
                    $tossWin = (string)($m['tossWinnerId'] ?? '');
                    $tossDec = ($m['tossDecision'] ?? 'bat') === 'bowl' ? 'bowl' : 'bat';
                    $status = (string)($m['status'] ?? 'live');
                    $stage = (string)($m['stage'] ?? 'league');
                    $resText = (string)($m['resultText'] ?? '');
                    $motm = !empty($m['manOfMatchId']) ? (string)$m['manOfMatchId'] : null;
                    $mTitle = (string)($m['matchTitle'] ?? '');
                    $ballType = (string)($m['ballType'] ?? 'Tennis Ball');
                    $season = (string)($m['season'] ?? '2025');
                    $payloadStr = json_encode($m, JSON_UNESCAPED_UNICODE);

                    $stmtUpsertMatch->execute([
                        $mid, $tournId, $user['uid'], $user['user_code'], $user['name'],
                        $teamAId, $teamBId, $teamAName, $teamBName,
                        $overs, $balls, $totalWickets, $lastMan, $widePen, $noBallPen,
                        $venue, $dateIso, $tossWin, $tossDec, $status, $stage,
                        $resText, $motm, $mTitle, $ballType, $season,
                        $payloadStr, $epoch
                    ]);

                    $syncedMatchIds[] = $mid;
                }
            }

            // 4. Sync Tournaments (Points Adjustments, Table & Player Overrides, Playoff Qualified Seeds)
            if (!empty($tournaments) && is_array($tournaments)) {
                $stmtCheckT = $pdo->prepare("SELECT owner_uid, updated_at_epoch FROM tournaments WHERE id = ? LIMIT 1");
                $stmtTourn = $pdo->prepare("INSERT INTO tournaments 
                    (id, name, owner_uid, venue, logo_base64, format, overs_limit, status, is_published, tournament_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), 
                        venue = VALUES(venue),
                        logo_base64 = COALESCE(NULLIF(VALUES(logo_base64), ''), logo_base64),
                        overs_limit = VALUES(overs_limit),
                        status = VALUES(status),
                        is_published = VALUES(is_published),
                        tournament_payload = VALUES(tournament_payload), 
                        updated_at_epoch = VALUES(updated_at_epoch)");

                foreach ($tournaments as $t) {
                    if (!is_array($t)) continue;
                    $tid = trim((string)($t['id'] ?? ''));
                    if ($tid === '') continue;

                    $epoch = (int)($t['updatedAtEpoch'] ?? $defaultEpoch);
                    $stmtCheckT->execute([$tid]);
                    $existingT = $stmtCheckT->fetch();
                    if ($existingT) {
                        if ($existingT['owner_uid'] !== $user['uid'] && $user['role'] !== 'admin') {
                            $errors[] = "Tournament {$tid} skipped: Not owner.";
                            continue;
                        }
                        if ((int)$existingT['updated_at_epoch'] > $epoch) {
                            $syncedTournamentIds[] = $tid;
                            continue;
                        }
                    }

                    $tName = (string)($t['name'] ?? 'Tournament');
                    $tVenue = (string)($t['venue'] ?? '');
                    $tLogo = !empty($t['logoBase64']) ? (string)$t['logoBase64'] : null;
                    $tFormat = (string)($t['format'] ?? 'Round Robin');
                    $tOvers = (int)($t['oversLimit'] ?? 8);
                    $tStatus = !empty($t['qualificationFinalized']) ? 'completed' : 'ongoing';
                    $tPub = !empty($t['published']) ? 1 : 0;
                    $tPayload = json_encode($t, JSON_UNESCAPED_UNICODE);

                    $stmtTourn->execute([$tid, $tName, $user['uid'], $tVenue, $tLogo, $tFormat, $tOvers, $tStatus, $tPub, $tPayload, $epoch]);
                    $syncedTournamentIds[] = $tid;
                }
            }

            // 5. Sync Registered League Players (Registration & Draft Pool)
            if (!empty($regPlayers) && is_array($regPlayers)) {
                $stmtReg = $pdo->prepare("INSERT INTO reg_players
                    (id, owner_uid, name, role, availability, area, phone, remarks, batting_style, bowling_style, age, category, reg_status, team_name, jersey_no, is_captain, is_keeper, photo_base64, reg_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        role = VALUES(role),
                        availability = VALUES(availability),
                        area = VALUES(area),
                        phone = VALUES(phone),
                        remarks = VALUES(remarks),
                        batting_style = VALUES(batting_style),
                        bowling_style = VALUES(bowling_style),
                        age = VALUES(age),
                        category = VALUES(category),
                        reg_status = VALUES(reg_status),
                        team_name = VALUES(team_name),
                        jersey_no = VALUES(jersey_no),
                        is_captain = VALUES(is_captain),
                        is_keeper = VALUES(is_keeper),
                        photo_base64 = COALESCE(NULLIF(VALUES(photo_base64), ''), photo_base64),
                        reg_payload = VALUES(reg_payload),
                        updated_at_epoch = GREATEST(updated_at_epoch, VALUES(updated_at_epoch))");

                foreach ($regPlayers as $rp) {
                    if (!is_array($rp)) continue;
                    $rid = trim((string)($rp['id'] ?? ''));
                    if ($rid === '') continue;

                    $stmtReg->execute([
                        $rid,
                        $user['uid'],
                        (string)($rp['name'] ?? 'Player'),
                        (string)($rp['role'] ?? 'Batter'),
                        (string)($rp['availability'] ?? 'Available'),
                        (string)($rp['area'] ?? ''),
                        (string)($rp['phone'] ?? ''),
                        (string)($rp['remarks'] ?? ''),
                        (string)($rp['battingStyle'] ?? 'Right-hand'),
                        (string)($rp['bowlingStyle'] ?? 'None'),
                        (int)($rp['age'] ?? 0),
                        (string)($rp['category'] ?? ''),
                        (string)($rp['regStatus'] ?? 'Confirmed'),
                        (string)($rp['teamName'] ?? ''),
                        (int)($rp['jerseyNo'] ?? 0),
                        !empty($rp['isCaptain']) ? 1 : 0,
                        !empty($rp['isKeeper']) ? 1 : 0,
                        !empty($rp['photoBase64']) ? (string)$rp['photoBase64'] : null,
                        json_encode($rp, JSON_UNESCAPED_UNICODE),
                        (int)($rp['updatedAtEpoch'] ?? $defaultEpoch),
                    ]);
                    $syncedRegPlayerIds[] = $rid;
                }
            }

            // 6. Sync Drafts & Auction Picks
            if (!empty($drafts) && is_array($drafts)) {
                $stmtDraft = $pdo->prepare("INSERT INTO drafts
                    (id, tournament_id, owner_uid, name, status, applied_to_teams, draft_payload, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        tournament_id = VALUES(tournament_id),
                        name = VALUES(name),
                        status = VALUES(status),
                        applied_to_teams = VALUES(applied_to_teams),
                        draft_payload = VALUES(draft_payload),
                        updated_at_epoch = GREATEST(updated_at_epoch, VALUES(updated_at_epoch))");

                foreach ($drafts as $d) {
                    if (!is_array($d)) continue;
                    $did = trim((string)($d['id'] ?? ''));
                    if ($did === '') continue;

                    $stmtDraft->execute([
                        $did,
                        (string)($d['tournamentId'] ?? ''),
                        $user['uid'],
                        (string)($d['name'] ?? 'Draft'),
                        (string)($d['status'] ?? 'active'),
                        !empty($d['appliedToTeams']) ? 1 : 0,
                        json_encode($d, JSON_UNESCAPED_UNICODE),
                        (int)($d['updatedAtEpoch'] ?? $defaultEpoch),
                    ]);
                    $syncedDraftIds[] = $did;
                }
            }

            // 7. Sync Manual Career Stat Entries
            if (!empty($manualStats) && is_array($manualStats)) {
                $stmtManual = $pdo->prepare("INSERT INTO manual_stats
                    (id, owner_uid, player_id, player_name, match_label, date_iso, runs, balls, fours, sixes, not_out, wickets, runs_conceded, balls_bowled, catches, stumpings, updated_at_epoch)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        player_id = VALUES(player_id),
                        player_name = VALUES(player_name),
                        match_label = VALUES(match_label),
                        date_iso = VALUES(date_iso),
                        runs = VALUES(runs),
                        balls = VALUES(balls),
                        fours = VALUES(fours),
                        sixes = VALUES(sixes),
                        not_out = VALUES(not_out),
                        wickets = VALUES(wickets),
                        runs_conceded = VALUES(runs_conceded),
                        balls_bowled = VALUES(balls_bowled),
                        catches = VALUES(catches),
                        stumpings = VALUES(stumpings),
                        updated_at_epoch = GREATEST(updated_at_epoch, VALUES(updated_at_epoch))");

                foreach ($manualStats as $ms) {
                    if (!is_array($ms)) continue;
                    $msid = trim((string)($ms['id'] ?? ''));
                    if ($msid === '') continue;

                    $stmtManual->execute([
                        $msid,
                        $user['uid'],
                        (string)($ms['playerId'] ?? ''),
                        (string)($ms['playerName'] ?? ''),
                        (string)($ms['matchLabel'] ?? ''),
                        (string)($ms['dateIso'] ?? ''),
                        (int)($ms['runs'] ?? 0),
                        (int)($ms['balls'] ?? 0),
                        (int)($ms['fours'] ?? 0),
                        (int)($ms['sixes'] ?? 0),
                        !empty($ms['notOut']) ? 1 : 0,
                        (int)($ms['wickets'] ?? 0),
                        (int)($ms['runsConceded'] ?? 0),
                        (int)($ms['ballsBowled'] ?? 0),
                        (int)($ms['catches'] ?? 0),
                        (int)($ms['stumpings'] ?? 0),
                        (int)($ms['updatedAtEpoch'] ?? $defaultEpoch),
                    ]);
                    $syncedManualStatIds[] = $msid;
                }
            }

            // 8. Sync Deletion Tombstones & Purge Owner Records Older Than Tombstone
            if (!empty($tombstones) && is_array($tombstones)) {
                $stmtTomb = $pdo->prepare("INSERT INTO tombstones (doc_id, entity_kind, entity_id, owner_uid, deleted_at_epoch, device_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE deleted_at_epoch = GREATEST(deleted_at_epoch, VALUES(deleted_at_epoch))");

                foreach ($tombstones as $tb) {
                    if (!is_array($tb)) continue;
                    $kind = trim((string)($tb['kind'] ?? ''));
                    $eid = trim((string)($tb['id'] ?? ''));
                    $deletedAt = (int)($tb['deletedAt'] ?? 0);
                    $deviceId = (string)($tb['deviceId'] ?? '');
                    if ($kind === '' || $eid === '') continue;

                    $docId = $user['uid'] . '|' . $kind . '__' . $eid;
                    $stmtTomb->execute([$docId, $kind, $eid, $user['uid'], $deletedAt, $deviceId]);

                    if ($kind === 'match') {
                        $pdo->prepare("DELETE FROM matches WHERE id = ? AND creator_id = ? AND updated_at_epoch <= ?")
                            ->execute([$eid, $user['uid'], $deletedAt]);
                    } elseif ($kind === 'team') {
                        $pdo->prepare("DELETE FROM teams WHERE id = ? AND creator_id = ? AND updated_at_epoch <= ?")
                            ->execute([$eid, $user['uid'], $deletedAt]);
                    } elseif ($kind === 'tournament') {
                        $pdo->prepare("DELETE FROM tournaments WHERE id = ? AND owner_uid = ? AND updated_at_epoch <= ?")
                            ->execute([$eid, $user['uid'], $deletedAt]);
                    } elseif ($kind === 'regPlayer') {
                        $pdo->prepare("DELETE FROM reg_players WHERE id = ? AND owner_uid = ? AND updated_at_epoch <= ?")
                            ->execute([$eid, $user['uid'], $deletedAt]);
                    } elseif ($kind === 'draft') {
                        $pdo->prepare("DELETE FROM drafts WHERE id = ? AND owner_uid = ? AND updated_at_epoch <= ?")
                            ->execute([$eid, $user['uid'], $deletedAt]);
                    } elseif ($kind === 'manualStat') {
                        $pdo->prepare("DELETE FROM manual_stats WHERE id = ? AND owner_uid = ? AND updated_at_epoch <= ?")
                            ->execute([$eid, $user['uid'], $deletedAt]);
                    }
                    $syncedTombstoneIds[] = "{$kind}__{$eid}";
                }
            }

            $pdo->commit();

            Response::success([
                'synced_matches' => $syncedMatchIds,
                'synced_players' => $syncedPlayerIds,
                'synced_teams' => $syncedTeamIds,
                'synced_tournaments' => $syncedTournamentIds,
                'synced_reg_players' => $syncedRegPlayerIds,
                'synced_drafts' => $syncedDraftIds,
                'synced_manual_stats' => $syncedManualStatIds,
                'synced_tombstones' => $syncedTombstoneIds,
                'server_time' => $defaultEpoch,
                'warnings' => $errors
            ], 'Full offline batch sync processed successfully');

        } catch (Exception $e) {
            $pdo->rollBack();
            Response::error('Sync failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Multi-device Cloud Pull (GET /api/sync/pull):
     * Restores all entities owned/created by the authenticated account in exact Flutter JSON format.
     */
    public static function pullSync(): void {
        $user = AuthMiddleware::authenticate(true);
        $pdo = Database::getConnection();
        SchemaMigrator::ensureSchemaUpToDate($pdo);

        // 1. Profile
        $stmtP = $pdo->prepare("SELECT * FROM players WHERE linked_user_id = ? OR id = ? LIMIT 1");
        $stmtP->execute([$user['id'], $user['uid']]);
        $playerRow = $stmtP->fetch();

        $profile = [
            'uid' => $user['uid'],
            'displayName' => $user['name'],
            'contact' => $user['email'] ?: ($user['contact'] ?? ''),
            'userCode' => $user['user_code'],
            'role' => $playerRow['role'] ?? 'All-Rounder',
            'battingStyle' => $playerRow['batting_style'] ?? 'Right-hand bat',
            'bowlingStyle' => $playerRow['bowling_style'] ?? 'Right-arm fast-medium',
            'jerseyNumber' => (int)($playerRow['jersey_number'] ?? 7),
            'city' => $playerRow['city'] ?? ($user['city'] ?? ''),
            'isVerified' => true,
            'isSynced' => true,
        ];

        // 2. Teams (with full squad players)
        $stmtT = $pdo->prepare("SELECT * FROM teams WHERE creator_id = ? ORDER BY name ASC");
        $stmtT->execute([$user['uid']]);
        $teamsOut = [];
        foreach ($stmtT->fetchAll() as $tRow) {
            if (!empty($tRow['team_payload'])) {
                $decoded = json_decode($tRow['team_payload'], true);
                if (is_array($decoded)) {
                    $teamsOut[] = $decoded;
                    continue;
                }
            }
            $stmtTP = $pdo->prepare("SELECT p.*, tp.jersey_no as tp_jersey, tp.role as tp_role, tp.is_captain, tp.is_vice_captain, tp.is_keeper
                FROM team_players tp
                JOIN players p ON tp.player_id = p.id
                WHERE tp.team_id = ?");
            $stmtTP->execute([$tRow['id']]);
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
                    'email' => $sp['email'],
                    'userCode' => $sp['user_code'],
                    'city' => $sp['city'] ?? '',
                ];
            }
            $teamsOut[] = [
                'id' => $tRow['id'],
                'name' => $tRow['name'],
                'players' => $squad,
            ];
        }

        // 3. Tournaments
        $stmtTourns = $pdo->prepare("SELECT * FROM tournaments WHERE owner_uid = ? ORDER BY updated_at DESC");
        $stmtTourns->execute([$user['uid']]);
        $tournamentsOut = [];
        foreach ($stmtTourns->fetchAll() as $tr) {
            $decoded = json_decode($tr['tournament_payload'] ?? '{}', true);
            if (is_array($decoded) && !empty($decoded['id'])) {
                $tournamentsOut[] = $decoded;
            }
        }

        // 4. Matches
        $stmtMatches = $pdo->prepare("SELECT * FROM matches WHERE creator_id = ? ORDER BY updated_at DESC");
        $stmtMatches->execute([$user['uid']]);
        $matchesOut = [];
        foreach ($stmtMatches->fetchAll() as $mr) {
            $decoded = json_decode($mr['match_payload'] ?? '{}', true);
            if (is_array($decoded) && !empty($decoded['id'])) {
                $matchesOut[] = $decoded;
            }
        }

        // 5. RegPlayers
        $stmtReg = $pdo->prepare("SELECT * FROM reg_players WHERE owner_uid = ? ORDER BY name ASC");
        $stmtReg->execute([$user['uid']]);
        $regOut = [];
        foreach ($stmtReg->fetchAll() as $rr) {
            $regOut[] = [
                'id' => $rr['id'],
                'name' => $rr['name'],
                'role' => $rr['role'],
                'availability' => $rr['availability'],
                'area' => $rr['area'],
                'phone' => $rr['phone'],
                'remarks' => $rr['remarks'],
                'battingStyle' => $rr['batting_style'],
                'bowlingStyle' => $rr['bowling_style'],
                'age' => (int)$rr['age'],
                'category' => $rr['category'],
                'regStatus' => $rr['reg_status'],
                'teamName' => $rr['team_name'],
                'jerseyNo' => (int)$rr['jersey_no'],
                'isCaptain' => (bool)$rr['is_captain'],
                'isKeeper' => (bool)$rr['is_keeper'],
                'photoBase64' => $rr['photo_base64'] ?? '',
            ];
        }

        // 6. Drafts
        $stmtDrafts = $pdo->prepare("SELECT * FROM drafts WHERE owner_uid = ? ORDER BY updated_at DESC");
        $stmtDrafts->execute([$user['uid']]);
        $draftsOut = [];
        foreach ($stmtDrafts->fetchAll() as $dr) {
            $decoded = json_decode($dr['draft_payload'] ?? '{}', true);
            if (is_array($decoded) && !empty($decoded['id'])) {
                $draftsOut[] = $decoded;
            }
        }

        // 7. Manual Stats
        $stmtManual = $pdo->prepare("SELECT * FROM manual_stats WHERE owner_uid = ? ORDER BY created_at DESC");
        $stmtManual->execute([$user['uid']]);
        $manualOut = [];
        foreach ($stmtManual->fetchAll() as $msr) {
            $manualOut[] = [
                'id' => $msr['id'],
                'playerId' => $msr['player_id'],
                'playerName' => $msr['player_name'],
                'matchLabel' => $msr['match_label'],
                'dateIso' => $msr['date_iso'],
                'runs' => (int)$msr['runs'],
                'balls' => (int)$msr['balls'],
                'fours' => (int)$msr['fours'],
                'sixes' => (int)$msr['sixes'],
                'notOut' => (bool)$msr['not_out'],
                'wickets' => (int)$msr['wickets'],
                'runsConceded' => (int)$msr['runs_conceded'],
                'ballsBowled' => (int)$msr['balls_bowled'],
                'catches' => (int)$msr['catches'],
                'stumpings' => (int)$msr['stumpings'],
            ];
        }

        // 8. Tombstones
        $stmtTombs = $pdo->prepare("SELECT * FROM tombstones WHERE owner_uid = ?");
        $stmtTombs->execute([$user['uid']]);
        $tombsOut = [];
        foreach ($stmtTombs->fetchAll() as $tb) {
            $tombsOut[] = [
                'kind' => $tb['entity_kind'],
                'id' => $tb['entity_id'],
                'deletedAt' => (int)$tb['deleted_at_epoch'],
                'deviceId' => $tb['device_id'],
            ];
        }

        Response::success([
            'app' => '60Score',
            'schema' => 2,
            'pulledAt' => date('c'),
            'profile' => $profile,
            'teams' => $teamsOut,
            'tournaments' => $tournamentsOut,
            'matches' => $matchesOut,
            'regPlayers' => $regOut,
            'drafts' => $draftsOut,
            'manualStats' => $manualOut,
            'tombstones' => $tombsOut,
        ], 'Cloud workspace pulled successfully');
    }
}
