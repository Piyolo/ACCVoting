<?php
/**
 * draft.php
 *
 * "Save for later" for the student ballot. This is deliberately separate
 * from votes: a draft is never counted as an actual vote, is never touched
 * by tallying anywhere, and can be freely overwritten or discarded. It
 * exists purely so a student can step away mid-ballot (e.g. to go check a
 * candidate's platform on the Candidates page) and pick back up later
 * without losing their in-progress picks.
 *
 * Selections are stored as one JSON object per (student, election):
 *   {"5": [12], "7": [20, 21]}
 * keyed by position_id (string) -> array of candidate IDs picked so far.
 * The same shape covers both single-winner and multi-winner positions.
 *
 * GET  ?election_id=X  -> { selections: {...} }  (empty object if no draft)
 * POST { csrf_token, election_id, selections }   -> upsert the draft
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'You must be logged in.'], 401);
}

$pdo = getDbConnection();
$userId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Only ever touch drafts for elections the student can actually see (same
// visibility rule as the dashboard: SSG is open to everyone, DSG is scoped
// to one department), so this endpoint can't be used to poke at drafts
// tied to an election that isn't even shown to this student.
function studentCanSeeElection(PDO $pdo, int $electionId, array $voter): bool
{
    $stmt = $pdo->prepare("SELECT type, department FROM elections WHERE id = :id AND status != 'draft'");
    $stmt->execute(['id' => $electionId]);
    $election = $stmt->fetch();
    if (!$election) {
        return false;
    }
    return $election['type'] === 'SSG' || $election['department'] === $voter['department'];
}

if ($method === 'GET') {
    $electionId = isset($_GET['election_id']) ? (int) $_GET['election_id'] : 0;
    if ($electionId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Missing election.'], 400);
    }

    $voterStmt = $pdo->prepare('SELECT department FROM users WHERE id = :id');
    $voterStmt->execute(['id' => $userId]);
    $voter = $voterStmt->fetch();
    if (!$voter || !studentCanSeeElection($pdo, $electionId, $voter)) {
        jsonResponse(['success' => false, 'message' => 'Election not found.'], 404);
    }

    $stmt = $pdo->prepare('SELECT selections FROM vote_drafts WHERE user_id = :uid AND election_id = :eid');
    $stmt->execute(['uid' => $userId, 'eid' => $electionId]);
    $row = $stmt->fetch();

    $selections = $row ? (json_decode($row['selections'], true) ?: new stdClass()) : new stdClass();
    jsonResponse(['success' => true, 'selections' => $selections]);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonResponse(['success' => false, 'message' => 'Invalid request body.'], 400);
    }
    if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
        jsonResponse(['success' => false, 'message' => 'Your session expired. Please refresh and try again.'], 403);
    }

    $electionId = (int) ($input['election_id'] ?? 0);
    if ($electionId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Missing election.'], 400);
    }

    $voterStmt = $pdo->prepare('SELECT department FROM users WHERE id = :id');
    $voterStmt->execute(['id' => $userId]);
    $voter = $voterStmt->fetch();
    if (!$voter || !studentCanSeeElection($pdo, $electionId, $voter)) {
        jsonResponse(['success' => false, 'message' => 'Election not found.'], 404);
    }

    // Normalize the incoming selections into { "position_id": [candidate_id, ...] },
    // dropping anything that doesn't look like a real position/candidate id pair.
    // This is scratch data (never counted as a vote), so the goal here is just
    // to keep garbage out of the JSON column, not to fully re-validate ballot rules.
    $rawSelections = is_array($input['selections'] ?? null) ? $input['selections'] : [];
    $clean = [];
    foreach ($rawSelections as $positionId => $candidateIds) {
        $positionId = (int) $positionId;
        if ($positionId <= 0 || !is_array($candidateIds)) {
            continue;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $candidateIds), function ($id) {
            return $id > 0;
        })));
        if (!empty($ids)) {
            $clean[(string) $positionId] = $ids;
        }
    }

    $stmt = $pdo->prepare('
        INSERT INTO vote_drafts (user_id, election_id, selections, updated_at)
        VALUES (:uid, :eid, :selections::jsonb, NOW())
        ON CONFLICT (user_id, election_id)
        DO UPDATE SET selections = :selections2::jsonb, updated_at = NOW()
    ');
    $stmt->execute([
        'uid' => $userId,
        'eid' => $electionId,
        'selections' => json_encode($clean),
        'selections2' => json_encode($clean),
    ]);

    jsonResponse(['success' => true]);
}

jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);