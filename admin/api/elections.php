<?php
/**
 * admin/api/elections.php
 *
 * Real DB-backed CRUD for the admin "Election Management" panel.
 *
 * Data model recap (see schema.sql + migration.sql):
 *   elections            — one row per election (name, type, dept, schedule, status,
 *                           results_visibility, parties_enabled, parties JSONB array)
 *   positions            — a NEW row is created for every position added to an election
 *                           (titles are not shared/reused across elections on purpose,
 *                           so one election's candidates never leak into another's)
 *   election_positions   — links a position to an election, plus winner_count /
 *                           candidate_limit / year_restriction ("limit to see")
 *   candidates           — belongs to exactly one position_id (and therefore, transitively,
 *                           to exactly one election)
 *
 * IMPORTANT: positions.id -> election_positions.position_id and
 * positions.id -> candidates.position_id must both be ON DELETE CASCADE
 * (see migration.sql). That is what lets us clean up an election's old
 * positions/candidates/votes in one DELETE FROM positions WHERE id IN (...)
 * whenever the admin edits or deletes an election, instead of leaving
 * orphaned rows behind (which is what the first draft of this file did).
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getDbConnection();
syncElectionStatuses($pdo);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// Candidate photos are stored directly in the database as the same
// base64 data: URL the browser already sends — not written to local disk.
// Free-tier hosts like Render wipe local disk on every redeploy, which
// would silently blank out every candidate's photo; storing it in
// Postgres survives deploys/restarts with no extra service (S3,
// Cloudinary, etc.) to set up, and works identically in local dev and
// production. The tradeoff is DB size, which is why validatePayload()
// below caps how large a single photo can be.
//
// Only two shapes are accepted: a data:image/ URL (a real upload from the
// wizard) or a legacy assets/... path (candidates saved before photos
// moved into the database). Anything else is rejected rather than passed
// through — this field renders straight into an <img src="..."> in the
// admin wizard's own preview, so accepting arbitrary strings here would
// be a stored-XSS hole for anyone who could reach this endpoint directly
// (bypassing the file picker) rather than through the normal upload flow.
function saveCandidatePhoto(?string $base64): ?string
{
    if (!$base64) return null;
    if (strpos($base64, 'data:image/') === 0) return $base64;
    if (strpos($base64, 'assets/') === 0) return $base64; // legacy pre-migration path
    return null;
}

// Rough base64 -> decoded-byte-size estimate, good enough for a size cap.
function base64ApproxBytes(string $base64): int
{
    $comma = strpos($base64, ',');
    $encoded = $comma !== false ? substr($base64, $comma + 1) : $base64;
    return (int) (strlen($encoded) * 3 / 4);
}

function splitElectionType(array $input): array
{
    $type = $input['type'] ?? '';
    $department = $type === 'DSG' ? trim($input['department'] ?? '') : null;
    if ($department === '') $department = null;
    return [$type, $department];
}

function validatePayload(array $input, bool $requirePositions): array
{
    $errors = [];
    $type = $input['type'] ?? '';
    if (!in_array($type, ['SSG', 'DSG'], true)) $errors[] = 'Choose a valid election type.';
    if ($type === 'DSG' && empty(trim($input['department'] ?? ''))) $errors[] = 'Select a department.';
    if (empty(trim($input['name'] ?? ''))) {
        $errors[] = 'Enter an election name.';
    } elseif (mb_strlen(trim($input['name'])) > 100) {
        $errors[] = 'Election name is too long (max 100 characters).';
    }
    if (empty($input['start']) || empty($input['end'])) $errors[] = 'Set a start and end date/time.';
    if (!empty($input['start']) && !empty($input['end']) && strtotime($input['end']) <= strtotime($input['start'])) {
        $errors[] = 'End time must be after the start time.';
    }
    if (!empty($input['parties_enabled'])) {
        foreach (($input['parties'] ?? []) as $p) {
            if (trim((string)$p) === '') { $errors[] = 'Fill in all party names, or disable parties.'; break; }
            if (mb_strlen(trim((string)$p)) > 100) { $errors[] = 'Party name "' . mb_substr(trim((string)$p), 0, 30) . '…" is too long (max 100 characters).'; }
        }
    }

    if ($requirePositions) {
        $positions = $input['positions'] ?? [];
        if (!is_array($positions) || empty($positions)) {
            $errors[] = 'Add at least one position.';
        }
        foreach ($positions as $i => $p) {
            $rawTitle = trim($p['title'] ?? '');
            $label = $rawTitle !== '' ? mb_substr($rawTitle, 0, 50) : ('#' . ($i + 1));
            if ($rawTitle === '') {
                $errors[] = 'Position #' . ($i + 1) . ' needs a name.';
            } elseif (mb_strlen($rawTitle) > 50) {
                $errors[] = 'Position name "' . mb_substr($rawTitle, 0, 30) . '…" is too long (max 50 characters).';
            }
            $candidates = $p['candidates'] ?? [];
            $candCount = is_array($candidates) ? count($candidates) : 0;
            $winners = (int)($p['winner_count'] ?? 1);
            if ($candCount < 1) $errors[] = 'Position "' . $label . '" needs at least 1 candidate.';
            if ($winners < 1) $errors[] = 'Position "' . $label . '" needs at least 1 winner.';
            if ($winners > $candCount) $errors[] = 'Position "' . $label . '" can\'t have more winners than candidates.';
            foreach ($candidates as $j => $c) {
                $name = trim($c['name'] ?? '');
                if ($name === '') {
                    $errors[] = 'Candidate ' . ($j + 1) . ' in position "' . $label . '" needs a name.';
                } elseif (mb_strlen($name) > 100) {
                    // Matches the actual candidates.name column width —
                    // without this check, an over-length name reaches
                    // Postgres and fails as a raw, unhelpful 500 error
                    // instead of a clear validation message here.
                    $errors[] = 'Name for candidate ' . ($j + 1) . ' in position "' . $label . '" is too long (max 100 characters).';
                }
                if (!empty($c['course']) && mb_strlen($c['course']) > 100) {
                    $errors[] = 'Course/major for candidate ' . ($j + 1) . ' in position "' . $label . '" is too long (max 100 characters).';
                }
                if (!empty($c['party']) && mb_strlen($c['party']) > 100) {
                    $errors[] = 'Party for candidate ' . ($j + 1) . ' in position "' . $label . '" is too long (max 100 characters).';
                }
                $photo = $c['photo'] ?? '';
                if ($photo && strpos($photo, 'data:image/') === 0 && base64ApproxBytes($photo) > 2 * 1024 * 1024) {
                    $errors[] = 'Photo for candidate ' . ($j + 1) . ' in position "' . $label . '" is too large (max 2MB) — please use a smaller image.';
                }
            }
        }
    }

    return $errors;
}

// Delete a set of position rows. Thanks to ON DELETE CASCADE on
// election_positions.position_id and candidates.position_id (see
// migration.sql), this also removes their election_positions link rows,
// their candidates, and (via candidates -> votes cascade) any votes cast
// for those candidates.
function deletePositions(PDO $pdo, array $positionIds): void
{
    $positionIds = array_values(array_unique(array_map('intval', $positionIds)));
    if (!$positionIds) return;
    $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
    $pdo->prepare("DELETE FROM positions WHERE id IN ($placeholders)")->execute($positionIds);
}

function electionVoteCount(PDO $pdo, int $electionId): int
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM votes v
        JOIN election_positions ep ON ep.position_id = v.position_id
        WHERE ep.election_id = :eid
    ');
    $stmt->execute(['eid' => $electionId]);
    return (int) $stmt->fetchColumn();
}

function insertPositionsAndCandidates(PDO $pdo, int $electionId, array $positions, bool $partiesEnabled): void
{
    $posInsert = $pdo->prepare('INSERT INTO positions (title) VALUES (:title) RETURNING id');
    $epInsert = $pdo->prepare('
        INSERT INTO election_positions (election_id, position_id, winner_count, candidate_limit, year_restriction)
        VALUES (:eid, :pid, :winners, :limit_c, :year)
    ');
    $candInsert = $pdo->prepare('
        INSERT INTO candidates (position_id, name, photo, course, candidate_year, platform, party)
        VALUES (:pid, :name, :photo, :course, :year, :platform, :party)
    ');

    foreach ($positions as $posData) {
        $title = trim($posData['title']);
        $posInsert->execute(['title' => $title]);
        $posId = (int) $posInsert->fetchColumn();

        $candidates = $posData['candidates'] ?? [];
        $yearRestriction = trim($posData['year_restriction'] ?? '');

        $epInsert->execute([
            'eid' => $electionId,
            'pid' => $posId,
            'winners' => (int) ($posData['winner_count'] ?? 1),
            'limit_c' => count($candidates) ?: null,
            'year' => $yearRestriction !== '' ? $yearRestriction : null,
        ]);

        foreach ($candidates as $cand) {
            $photoPath = saveCandidatePhoto($cand['photo'] ?? null);
            $candInsert->execute([
                'pid' => $posId,
                'name' => trim($cand['name']),
                'photo' => $photoPath,
                'course' => $cand['course'] ?? '',
                'year' => $cand['candidate_year'] ?? '',
                'platform' => $cand['platform'] ?? '',
                'party' => $partiesEnabled ? ($cand['party'] ?: 'No Party / Independent') : 'No Party / Independent',
            ]);
        }
    }
}

// ------------------------------------------------------------------
// GET: list all elections, or fetch a single election with full detail
// ------------------------------------------------------------------
if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM elections WHERE id = :id');
        $stmt->execute(['id' => (int) $id]);
        $election = $stmt->fetch();

        if (!$election) {
            respond(['error' => 'Election not found'], 404);
        }

        $posStmt = $pdo->prepare('
            SELECT ep.position_id, p.title, ep.winner_count, ep.candidate_limit, ep.year_restriction
            FROM election_positions ep
            JOIN positions p ON p.id = ep.position_id
            WHERE ep.election_id = :eid
            ORDER BY ep.id
        ');
        $posStmt->execute(['eid' => $election['id']]);
        $positions = $posStmt->fetchAll();

        $candStmt = $pdo->prepare('
            SELECT c.id, c.name, c.photo, c.course, c.candidate_year, c.platform, c.party,
                   COUNT(v.id) AS vote_count
            FROM candidates c
            LEFT JOIN votes v ON v.candidate_id = c.id
            WHERE c.position_id = :pid
            GROUP BY c.id
            ORDER BY c.name ASC
        ');
        foreach ($positions as &$pos) {
            $candStmt->execute(['pid' => $pos['position_id']]);
            $pos['winner_count'] = (int) $pos['winner_count'];
            $pos['candidate_limit'] = $pos['candidate_limit'] !== null ? (int) $pos['candidate_limit'] : null;
            $pos['candidates'] = array_map(function ($c) {
                $c['vote_count'] = (int) $c['vote_count'];
                return $c;
            }, $candStmt->fetchAll());
        }
        unset($pos);

        $election['positions'] = $positions;
        $election['parties'] = json_decode($election['parties'] ?? '[]', true) ?: [];
        $election['parties_enabled'] = (bool) $election['parties_enabled'];
        $election['vote_count'] = electionVoteCount($pdo, (int) $election['id']);

        respond($election);
    }

    $stmt = $pdo->query('
        SELECT id, name, type, department, status, start_date, end_date,
               results_visibility, parties_enabled, parties
        FROM elections
        ORDER BY start_date DESC
    ');
    $list = $stmt->fetchAll();
    foreach ($list as &$e) {
        $e['parties'] = json_decode($e['parties'] ?? '[]', true) ?: [];
        $e['parties_enabled'] = (bool) $e['parties_enabled'];
    }
    unset($e);
    respond($list);
}

// Every other method is a write — require CSRF from the JSON body.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(['error' => 'Invalid request body.'], 400);
}
if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

// ------------------------------------------------------------------
// POST: create a brand-new election (always a "full" payload)
// ------------------------------------------------------------------
if ($method === 'POST') {
    $errors = validatePayload($input, true);
    if ($errors) respond(['error' => implode(' ', $errors)], 400);

    [$type, $department] = splitElectionType($input);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            INSERT INTO elections (name, type, department, status, start_date, end_date,
                                    results_visibility, parties_enabled, parties)
            VALUES (:name, :type, :department, :status, :start, :end, :visibility, :parties_enabled, :parties::jsonb)
            RETURNING id
        ');
        $stmt->execute([
            'name' => trim($input['name']),
            'type' => $type,
            'department' => $department,
            // New elections start as "Not Started". They move to "Upcoming"
            // the moment the admin saves a schedule (see the PUT handler
            // below), and from there start/end automatically on their own
            // — see syncElectionStatuses() in includes/functions.php.
            'status' => 'draft',
            'start' => str_replace('T', ' ', $input['start']),
            'end' => str_replace('T', ' ', $input['end']),
            'visibility' => $input['results_visibility'] ?? 'after',
            // IMPORTANT: cast bool to 1/0, not a raw PHP bool. PDO stringifies
            // `false` to an empty string when bound as a plain parameter,
            // and Postgres rejects '' as invalid boolean input — 1/0 (which
            // PHP stringifies to '1'/'0') are valid boolean literals instead.
            'parties_enabled' => !empty($input['parties_enabled']) ? 1 : 0,
            'parties' => json_encode($input['parties'] ?? []),
        ]);
        $electionId = (int) $stmt->fetchColumn();

        insertPositionsAndCandidates($pdo, $electionId, $input['positions'], !empty($input['parties_enabled']));

        $pdo->commit();
        respond(['success' => true, 'id' => $electionId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Create election failed: ' . $e->getMessage());
        respond(['error' => 'Could not create the election.'], 500);
    }
}

// ------------------------------------------------------------------
// PUT: update an election.
//   - If the payload includes "positions", it's a full save: name/type/
//     schedule/parties AND the whole position/candidate roster are
//     replaced. Blocked once the election has recorded votes.
//   - If it doesn't, it's a lightweight patch (used for the Start/Pause/
//     End buttons, the schedule box, and the results-visibility toggle) —
//     only the fields actually present in the payload are touched.
// ------------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) respond(['error' => 'Invalid election ID.'], 400);

    $existingStmt = $pdo->prepare('SELECT * FROM elections WHERE id = :id');
    $existingStmt->execute(['id' => $id]);
    $current = $existingStmt->fetch();
    if (!$current) respond(['error' => 'Election not found.'], 404);

    $hasPositions = array_key_exists('positions', $input);

    try {
        if (!$hasPositions) {
            // Lightweight patch: only touch fields that were actually sent.
            $fields = [];
            $values = ['id' => $id];

            if (array_key_exists('status', $input)) {
                if (!in_array($input['status'], ['draft', 'scheduled', 'ongoing', 'paused', 'closed', 'archived'], true)) {
                    respond(['error' => 'Invalid status.'], 400);
                }
                if ($input['status'] === 'archived' && $current['status'] !== 'closed') {
                    respond(['error' => 'Only ended elections can be archived.'], 409);
                }
                if ($input['status'] === 'ongoing' && $current['status'] === 'draft') {
                    respond(['error' => 'Save a schedule for this election before starting it.'], 409);
                }
                $fields[] = 'status = :status';
                $values['status'] = $input['status'];
            }
            if (array_key_exists('start', $input) || array_key_exists('end', $input)) {
                $start = $input['start'] ?? str_replace(' ', 'T', substr($current['start_date'], 0, 16));
                $end = $input['end'] ?? str_replace(' ', 'T', substr($current['end_date'], 0, 16));
                if (strtotime($end) <= strtotime($start)) {
                    respond(['error' => 'End time must be after the start time.'], 400);
                }
                if ($current['status'] === 'ongoing') {
                    respond(['error' => 'Pause this election before changing its schedule.'], 409);
                }
                $fields[] = 'start_date = :start_date';
                $fields[] = 'end_date = :end_date';
                $values['start_date'] = str_replace('T', ' ', $start);
                $values['end_date'] = str_replace('T', ' ', $end);
                // Saving a schedule is what moves a brand-new election from
                // "Not Started" to "Upcoming" — and it's automatic from here
                // on out (see syncElectionStatuses()), unless it's already
                // further along in its lifecycle (ongoing/paused/closed/archived).
                if ($current['status'] === 'draft') {
                    $fields[] = "status = 'scheduled'";
                }
            }
            if (array_key_exists('results_visibility', $input)) {
                if (!in_array($input['results_visibility'], ['always', 'after', 'never'], true)) {
                    respond(['error' => 'Invalid results visibility.'], 400);
                }
                $fields[] = 'results_visibility = :vis';
                $values['vis'] = $input['results_visibility'];
            }

            if (!$fields) respond(['error' => 'Nothing to update.'], 400);

            $pdo->prepare('UPDATE elections SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($values);
            respond(['success' => true]);
        }

        // Full save (name/type/schedule/parties + positions/candidates).
        if ($current['status'] === 'ongoing') {
            respond(['error' => 'This election is ongoing. Pause it before editing positions or candidates.'], 409);
        }
        if (electionVoteCount($pdo, $id) > 0) {
            respond(['error' => 'This election already has votes recorded, so its positions and candidates can\'t be edited anymore. You can still change its schedule, status, or results visibility.'], 409);
        }

        $errors = validatePayload($input, true);
        if ($errors) respond(['error' => implode(' ', $errors)], 400);

        [$type, $department] = splitElectionType($input);

        $pdo->beginTransaction();

        $statusClause = $current['status'] === 'draft' ? ", status = 'scheduled'" : '';
        $pdo->prepare('
            UPDATE elections SET
                name = :name, type = :type, department = :department,
                start_date = :start, end_date = :end,
                results_visibility = :visibility, parties_enabled = :parties_enabled, parties = :parties::jsonb
                ' . $statusClause . '
            WHERE id = :id
        ')->execute([
            'id' => $id,
            'name' => trim($input['name']),
            'type' => $type,
            'department' => $department,
            'start' => str_replace('T', ' ', $input['start']),
            'end' => str_replace('T', ' ', $input['end']),
            'visibility' => $input['results_visibility'] ?? 'after',
            // Cast to 1/0 — see the note in the POST handler above about why
            // binding a raw PHP bool breaks for `false`.
            'parties_enabled' => !empty($input['parties_enabled']) ? 1 : 0,
            'parties' => json_encode($input['parties'] ?? []),
        ]);

        // Wipe the old positions (cascades election_positions + candidates + votes
        // for those candidates — see deletePositions() docblock above) and
        // recreate them fresh. Safe here because we already confirmed above
        // that this election has zero votes recorded.
        $oldPosStmt = $pdo->prepare('SELECT position_id FROM election_positions WHERE election_id = :id');
        $oldPosStmt->execute(['id' => $id]);
        deletePositions($pdo, $oldPosStmt->fetchAll(PDO::FETCH_COLUMN));

        insertPositionsAndCandidates($pdo, $id, $input['positions'], !empty($input['parties_enabled']));

        $pdo->commit();
        respond(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Update election failed: ' . $e->getMessage());
        respond(['error' => 'Could not save changes.'], 500);
    }
}

// ------------------------------------------------------------------
// DELETE: remove an election and everything that belongs only to it
// ------------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) respond(['error' => 'Invalid election ID.'], 400);

    try {
        $pdo->beginTransaction();

        $posStmt = $pdo->prepare('SELECT position_id FROM election_positions WHERE election_id = :id');
        $posStmt->execute(['id' => $id]);
        $positionIds = $posStmt->fetchAll(PDO::FETCH_COLUMN);

        $del = $pdo->prepare('DELETE FROM elections WHERE id = :id');
        $del->execute(['id' => $id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            respond(['error' => 'Election not found.'], 404);
        }

        // Positions created for this election aren't shared with any other
        // election, so it's safe to remove them (and, via cascade, their
        // candidates and votes) once the election row itself is gone.
        deletePositions($pdo, $positionIds);

        $pdo->commit();
        respond(['success' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Delete election failed: ' . $e->getMessage());
        respond(['error' => 'Could not delete the election.'], 500);
    }
}

respond(['error' => 'Method not allowed.'], 405);