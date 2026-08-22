<?php
/**
 * dashboard-data.php
 *
 * JSON endpoint for the student dashboard's SPA panels (Home, Vote-picker,
 * Candidates, Results — see dashboard.php's "Branch B"). Returns the exact
 * same election data that used to be computed once per full page load in
 * dashboard.php, just as JSON instead of being used to render HTML
 * server-side.
 *
 * Deliberately NOT used by the actual ballot-casting page (dashboard.php's
 * "Branch A", ?page=vote&election_id=X) — that stays a full server-rendered
 * page and posts straight to vote.php/draft.php as it always has.
 *
 * "answeredPositionIds" per election is the one piece of derived data this
 * endpoint pre-merges server-side (real votes + saved drafts) rather than
 * shipping both separately and merging in JS — keeps the client simpler
 * and matches exactly what the old PHP page did per election card.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();
requireLogin();

header('Content-Type: application/json');

$pdo = getDbConnection();
syncElectionStatuses($pdo);

$stmt = $pdo->prepare('SELECT id, name, has_voted, department, year_level FROM users WHERE id = :id');
$stmt->execute(['id' => $_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

// Real cast votes, per position — matches userHasVotedInElection()'s
// underlying data source in dashboard.php.
$votedPositionIds = [];
$vpStmt = $pdo->prepare('SELECT position_id FROM votes WHERE user_id = :uid');
$vpStmt->execute(['uid' => $user['id']]);
$votedPositionIds = array_map('intval', $vpStmt->fetchAll(PDO::FETCH_COLUMN));

// Saved-for-later ballot drafts, keyed by election_id — never counted as
// actual votes, only used to show "X of Y positions" progress before
// submission (same as dashboard.php's Branch A / Branch B always did).
$draftsByElection = [];
$draftStmt = $pdo->prepare('SELECT election_id, selections FROM vote_drafts WHERE user_id = :uid');
$draftStmt->execute(['uid' => $user['id']]);
foreach ($draftStmt->fetchAll() as $draftRow) {
    $decoded = json_decode($draftRow['selections'], true);
    $draftsByElection[(int) $draftRow['election_id']] = is_array($decoded) ? $decoded : [];
}

function userHasVotedInElection(PDO $pdo, int $userId, int $electionId): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM votes v
        JOIN election_positions ep ON ep.position_id = v.position_id
        WHERE v.user_id = :uid AND ep.election_id = :eid
    ');
    $stmt->execute(['uid' => $userId, 'eid' => $electionId]);
    return (int) $stmt->fetchColumn() > 0;
}

$rawElections = $pdo->query("SELECT * FROM elections WHERE status != 'draft' ORDER BY start_date ASC")->fetchAll();
$elections = [];
$ssgLogo = getSiteSetting($pdo, 'ssg_logo');
$departmentLogos = getDepartmentLogos($pdo);

foreach ($rawElections as $e) {
    if ($e['type'] === 'DSG' && $e['department'] !== $user['department']) {
        continue; // not this student's department
    }

    $posStmt = $pdo->prepare('
        SELECT ep.position_id, p.title, ep.winner_count, ep.candidate_limit, ep.year_restriction
        FROM election_positions ep
        JOIN positions p ON p.id = ep.position_id
        WHERE ep.election_id = :eid
        ORDER BY ep.id ASC
    ');
    $posStmt->execute(['eid' => $e['id']]);
    $allPositions = $posStmt->fetchAll();

    // "Limit to see" — hide positions restricted to a year level this
    // student isn't in.
    $positions = array_values(array_filter($allPositions, function ($p) use ($user) {
        return empty($p['year_restriction']) || $p['year_restriction'] === $user['year_level'];
    }));

    $currentDraft = $draftsByElection[(int) $e['id']] ?? [];

    $positionsOut = [];
    $answeredPositionIds = [];
    foreach ($positions as $p) {
        $pid = (int) $p['position_id'];

        $candStmt = $pdo->prepare('
            SELECT c.id, c.name, c.photo, c.party, c.course,
                   c.candidate_year AS year_level, c.platform,
                   COUNT(v.id) AS vote_count
            FROM candidates c
            LEFT JOIN votes v ON v.candidate_id = c.id
            WHERE c.position_id = :pid
            GROUP BY c.id, c.name, c.photo, c.party, c.course, c.candidate_year, c.platform
            ORDER BY c.name ASC
        ');
        $candStmt->execute(['pid' => $pid]);
        $candidates = array_map(function ($c) {
            $c['id'] = (int) $c['id'];
            $c['vote_count'] = (int) $c['vote_count'];
            return $c;
        }, $candStmt->fetchAll());

        $positionsOut[] = [
            'position_id' => $pid,
            'title' => $p['title'],
            'winner_count' => (int) ($p['winner_count'] ?? 1),
            'year_restriction' => $p['year_restriction'] ?: null,
            'candidates' => $candidates,
        ];

        // A position counts as "answered" for the progress stepper if the
        // student has a real recorded vote OR a saved draft pick for it —
        // matches exactly what dashboard.php's per-card logic used to do.
        $hasRealVote = in_array($pid, $votedPositionIds, true);
        $hasDraftPick = !empty($currentDraft[(string) $pid]);
        if ($hasRealVote || $hasDraftPick) {
            $answeredPositionIds[] = $pid;
        }
    }

    $elections[] = [
        'id' => (int) $e['id'],
        'config' => [
            'id' => (int) $e['id'],
            'name' => $e['name'],
            'type' => $e['type'],
            'department' => $e['department'],
            'status' => $e['status'],
            'start_date' => $e['start_date'],
            'end_date' => $e['end_date'],
            'results_visibility' => $e['results_visibility'],
            'accent' => $e['type'] === 'SSG' ? '#3b82f6' : '#f97316',
            'icon' => electionLogoHtml($e['type'], $e['department'], $ssgLogo, $departmentLogos),
            'subtitle' => $e['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government' . ($e['department'] ? ' — ' . $e['department'] : ''),
        ],
        'positions' => $positionsOut,
        'totalPositions' => count($positionsOut),
        'hasVoted' => userHasVotedInElection($pdo, (int) $user['id'], (int) $e['id']),
        'answeredPositionIds' => $answeredPositionIds,
    ];
}

echo json_encode([
    'user' => [
        'name' => $user['name'],
        'department' => $user['department'],
        'year_level' => $user['year_level'],
    ],
    'elections' => $elections,
]);