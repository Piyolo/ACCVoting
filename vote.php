<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();
header('Content-Type: application/json');

// Must be logged in.
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'You must be logged in to vote.'], 401);
}

// Only accept POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request method.'], 405);
}

// CSRF check.
if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    jsonResponse(['success' => false, 'message' => 'Your session expired. Please refresh and try again.'], 403);
}

$userId = (int) $_SESSION['user_id'];
$electionId = (int) ($_POST['election_id'] ?? 0);

if ($electionId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Missing election.'], 400);
}

$pdo = getDbConnection();
syncElectionStatuses($pdo);

try {
    $pdo->beginTransaction();

    // Lock the user row so two rapid submissions (double-click, two tabs)
    // can't both pass validation before either has inserted a vote.
    $stmt = $pdo->prepare('SELECT id, department, year_level FROM users WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $userId]);
    $voter = $stmt->fetch();

    if (!$voter) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'User not found.'], 404);
    }

    // Load the election and confirm it's actually open right now.
    $eStmt = $pdo->prepare('SELECT * FROM elections WHERE id = :id');
    $eStmt->execute(['id' => $electionId]);
    $election = $eStmt->fetch();

    if (!$election) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'Election not found.'], 404);
    }

    $now = new DateTime();
    $start = new DateTime($election['start_date']);
    $end = new DateTime($election['end_date']);
    $isOpen = ($election['status'] === 'ongoing' && $now >= $start && $now <= $end);

    if (!$isOpen) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'This election is not currently open.'], 400);
    }

    // Department scoping: a DSG election is only votable by students in that department.
    if ($election['type'] === 'DSG' && $election['department'] !== $voter['department']) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'You are not eligible to vote in this election.'], 403);
    }

    // Already voted in this specific election? (all-or-nothing: a completed
    // ballot means at least one recorded vote against one of its positions.)
    $votedStmt = $pdo->prepare('
        SELECT COUNT(*) FROM votes v
        JOIN election_positions ep ON ep.position_id = v.position_id
        WHERE v.user_id = :uid AND ep.election_id = :eid
    ');
    $votedStmt->execute(['uid' => $userId, 'eid' => $electionId]);
    if ((int) $votedStmt->fetchColumn() > 0) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'You have already voted in this election.'], 409);
    }

    // The exact positions this voter is allowed to see/vote for in this
    // election — same "limit to see" year-level rule as the dashboard.
    // winner_count is fetched here too, since it caps how many candidates
    // the student is allowed to pick for a multi-winner position below.
    $posStmt = $pdo->prepare("
        SELECT ep.position_id, ep.year_restriction, ep.winner_count
        FROM election_positions ep
        WHERE ep.election_id = :eid
          AND (ep.year_restriction IS NULL OR ep.year_restriction = '' OR ep.year_restriction = :year)
    ");
    $posStmt->execute(['eid' => $electionId, 'year' => $voter['year_level']]);
    $allowedPositions = $posStmt->fetchAll();

    if (empty($allowedPositions)) {
        $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => 'No positions are available to you in this election.'], 400);
    }

    // Candidates don't carry an election_id column — position_id alone is
    // enough here because $positionId always comes from $allowedPositions,
    // which was already scoped to $electionId above.
    $candidateStmt = $pdo->prepare('SELECT id FROM candidates WHERE id = :id AND position_id = :position_id');
    $insertVoteStmt = $pdo->prepare(
        'INSERT INTO votes (user_id, candidate_id, position_id) VALUES (:user_id, :candidate_id, :position_id)'
    );

    $votesToCast = [];

    foreach ($allowedPositions as $posRow) {
        $positionId = (int) $posRow['position_id'];
        $winnerCount = max(1, (int) $posRow['winner_count']);
        $fieldName = 'position_' . $positionId;

        // Single-winner positions submit one value (position_X, a radio
        // group). Multi-winner positions submit an array (position_X[], a
        // checkbox group) — PHP collapses that "[]" suffix automatically,
        // so $_POST[$fieldName] just comes through as an array already.
        $rawValue = $_POST[$fieldName] ?? null;
        if (is_array($rawValue)) {
            $selectedIds = array_map('intval', $rawValue);
        } elseif ($rawValue !== null && $rawValue !== '') {
            $selectedIds = [(int) $rawValue];
        } else {
            $selectedIds = [];
        }
        $selectedIds = array_values(array_unique(array_filter($selectedIds, function ($id) {
            return $id > 0;
        })));

        if (empty($selectedIds)) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'Please select a candidate for every position.'], 400);
        }

        if (count($selectedIds) > $winnerCount) {
            $pdo->rollBack();
            $label = $winnerCount === 1 ? '1 candidate' : $winnerCount . ' candidates';
            jsonResponse(['success' => false, 'message' => "You can select up to $label for this position."], 400);
        }

        foreach ($selectedIds as $candidateId) {
            // Confirm the candidate actually belongs to this position AND this election.
            $candidateStmt->execute(['id' => $candidateId, 'position_id' => $positionId]);
            if (!$candidateStmt->fetch()) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Invalid candidate selection.'], 400);
            }

            $votesToCast[] = ['candidate_id' => $candidateId, 'position_id' => $positionId];
        }
    }

    // All selections validated — insert them all together.
    foreach ($votesToCast as $vote) {
        $insertVoteStmt->execute([
            'user_id' => $userId,
            'candidate_id' => $vote['candidate_id'],
            'position_id' => $vote['position_id'],
        ]);
    }

    // Legacy "has voted somewhere at least once" convenience flag, kept for
    // login.php's post-login redirect. Per-election status is derived from
    // the votes themselves (see userHasVotedInElection() in dashboard.php).
    $updateStmt = $pdo->prepare('UPDATE users SET has_voted = 1 WHERE id = :id');
    $updateStmt->execute(['id' => $userId]);

    // The ballot is now finalized — any "save for later" draft for this
    // election is stale scratch data at this point, so clear it out.
    $pdo->prepare('DELETE FROM vote_drafts WHERE user_id = :uid AND election_id = :eid')
        ->execute(['uid' => $userId, 'eid' => $electionId]);

    $pdo->commit();

    jsonResponse(['success' => true, 'message' => 'Your vote has been recorded. Thank you for voting!']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Unique constraint violation on (user_id, candidate_id) — belt-and-braces
    // in case of a race condition despite the row lock above.
    error_log('Vote insertion failed: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Could not record your vote. You may have already voted.'], 409);
}