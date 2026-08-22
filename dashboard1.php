<?php
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/config/db.php';

    startSecureSession();
    requireLogin();

    $pdo = getDbConnection();
    syncElectionStatuses($pdo);

    // Get current user
    $stmt = $pdo->prepare('SELECT id, name, has_voted, department, year_level FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    /**
     * Whether this student has already cast a ballot in a SPECIFIC election.
     * (users.has_voted is only a legacy "voted at least once, anywhere" flag —
     * with multiple elections we need a per-election answer, derived from the
     * votes actually on file for that election's positions.)
     */
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

    /**
     * Turns an election's raw status + schedule into what the UI shows.
     * "ongoing" from the admin panel is authoritative; the date range is a
     * safety net in case the admin forgets to close something on time.
     */
    function electionUiStatus(array $config, DateTime $now): array
    {
        $start = new DateTime($config['start_date']);
        $end = new DateTime($config['end_date']);
        $isOpen = ($config['status'] === 'ongoing' && $now >= $start && $now <= $end);

        if ($isOpen) {
            $statusText = 'Ongoing';
            $statusClass = '';
        } elseif ($config['status'] === 'paused') {
            $statusText = 'Paused';
            $statusClass = 'paused';
        } elseif (in_array($config['status'], ['draft', 'scheduled'], true) || $now < $start) {
            $statusText = 'Not started';
            $statusClass = 'scheduled';
        } else {
            // Covers 'closed' and 'archived' — archiving just tidies up the
            // admin's Elections list, it doesn't change how a finished
            // election looks to students.
            $statusText = 'Ended';
            $statusClass = 'closed';
        }

        return [$isOpen, $statusText, $statusClass, $start, $end];
    }

    // --------------------------------
    // REAL ELECTION DATA — pulled from elections / election_positions /
    // positions / candidates. Only elections relevant to this student are
    // included:
    //   - SSG elections are visible to everyone.
    //   - DSG elections are visible only to students in that department.
    // Within a visible election, positions are further filtered by their
    // "limit to see" (year_restriction): a position limited to a specific
    // year level is only shown to students in that year level.
    // --------------------------------
    $electionData = [];
    $rawElections = $pdo->query("SELECT * FROM elections WHERE status != 'draft' ORDER BY start_date ASC")->fetchAll();

    foreach ($rawElections as $e) {
        if ($e['type'] === 'DSG' && $e['department'] !== $user['department']) {
            continue; // not this student's department
        }

        $posStmt = $pdo->prepare('
            SELECT ep.id AS ep_id, ep.position_id, p.title, ep.winner_count, ep.candidate_limit, ep.year_restriction
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

        $candidatesByPosition = [];
        if ($positions) {
            // NOTE: candidates don't carry their own election_id column — a
            // candidate's election is derived through position_id, because
            // the admin panel always creates a brand-new positions row for
            // every position added to an election (titles are never reused
            // across elections), so position_id alone is enough to scope
            // this query correctly.
            $candStmt = $pdo->prepare('
                SELECT c.id, c.position_id, c.name, c.photo, c.party, c.course,
                       c.candidate_year AS year_level, c.platform,
                       COUNT(v.id) AS vote_count
                FROM candidates c
                LEFT JOIN votes v ON v.candidate_id = c.id
                WHERE c.position_id = :pid
                GROUP BY c.id, c.position_id, c.name, c.photo, c.party, c.course, c.candidate_year, c.platform
                ORDER BY c.name ASC
            ');
            foreach ($positions as $p) {
                $candStmt->execute(['pid' => $p['position_id']]);
                $candidatesByPosition[$p['position_id']] = $candStmt->fetchAll();
            }
        }

        $electionData[$e['id']] = [
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
            ],
            'positions' => $positions,
            'candidatesByPosition' => $candidatesByPosition,
            'totalPositions' => count($positions),
            'hasVoted' => userHasVotedInElection($pdo, (int) $user['id'], (int) $e['id']),
        ];
    }

    // Determine page
    $page = $_GET['page'] ?? 'home';
    $selectedElectionId = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;

    $csrfToken = csrfToken();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard | School Elections</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body { margin: 0; font-family: system-ui, -apple-system, sans-serif; background: #f4f7fa; display: flex; min-height: 100vh; overflow-x: hidden; }

    /* ----- Sidebar with fixed bottom profile & logout ----- */
    .sidebar {
        width: 240px;
        background: #1e293b;
        color: #e2e8f0;
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        z-index: 1000;
        transition: transform 0.3s ease;
        overflow: hidden;
    }
    .sidebar .logo-area {
        padding: 1.5rem 1.5rem 1rem;
        text-align: center;
        flex-shrink: 0;
    }
    .sidebar .logo-area img { max-width: 100px; height: auto; }
    .sidebar .logo-area h2 { color: white; margin: 0.5rem 0 0; font-weight: 400; font-size: 1.1rem; }

    .sidebar nav {
        flex: 1;
        overflow-y: auto;
        padding: 0 0 0.5rem 0;
    }
    .sidebar nav a {
        display: block;
        padding: 0.75rem 1.5rem;
        color: #cbd5e1;
        text-decoration: none;
        border-left: 3px solid transparent;
        transition: background 0.2s, border-color 0.2s;
    }
    .sidebar nav a:hover { background: #334155; border-left-color: #84cc16; }
    .sidebar nav a.active { background: #334155; border-left-color: #84cc16; color: white; }

    .sidebar .profile-section {
        padding: 1rem 1.5rem;
        border-top: 1px solid #334155;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-shrink: 0;
        background: #1e293b;
    }
    .sidebar .profile-section .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #84cc16;
        color: #052e16;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .sidebar .profile-section .user-info { flex: 1; overflow: hidden; }
    .sidebar .profile-section .user-info .name { font-weight: 500; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sidebar .profile-section .user-info .role { font-size: 0.75rem; color: #94a3b8; }
    .sidebar .logout-link {
        padding: 0;
        border-top: 1px solid #334155;
        flex-shrink: 0;
        background: #1e293b;
    }
    .sidebar .logout-link a {
        display: block;
        padding: 0.75rem 1.5rem;
        color: #f87171;
        text-decoration: none;
        border-left: 3px solid transparent;
        transition: background 0.2s, border-color 0.2s;
    }
    .sidebar .logout-link a:hover { background: #334155; border-left-color: #f87171; text-decoration: none; }

    /* Main content */
    .main-content {
        margin-left: 240px;
        flex: 1;
        padding: 2rem;
        transition: margin-left 0.3s;
        min-width: 0;
    }
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e2e8f0;
        flex-wrap: wrap;
        gap: 0.5rem;
        position: sticky;
        top: 0;
        background: #f4f7fa;
        z-index: 500;
        padding-top: 0.5rem;
        padding-bottom: 0.75rem;
    }
    .page-header h1 { margin: 0; font-weight: 400; color: #1e293b; font-size: 1.5rem; }
    .page-header .date { font-size: 0.95rem; color: #64748b; }
    .hamburger { display: none; background: none; border: none; font-size: 1.8rem; color: #1e293b; cursor: pointer; padding: 0.25rem 0.5rem; line-height: 1; min-width: 44px; min-height: 44px; align-items: center; justify-content: center; }
    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 999; opacity: 0; transition: opacity 0.3s; }
    .sidebar-overlay.active { display: block; opacity: 1; }

    /* Stats bar */
    .stats-bar {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .stat-box {
        background: white;
        border-radius: 16px;
        padding: 1rem 1.25rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        text-align: center;
    }
    .stat-box .stat-number {
        font-size: 2rem;
        font-weight: 700;
        color: #0f172a;
    }
    .stat-box .stat-label {
        font-size: 0.85rem;
        color: #64748b;
        margin-top: 0.25rem;
    }
    .section-title {
        font-size: 1.5rem;
        font-weight: 600;
        color: #0f172a;
        margin: 1.5rem 0 1rem;
    }

    /* ----- Election Cards ----- */
    .election-cards { display: flex; flex-direction: column; gap: 1.5rem; }
    .election-card {
        background: white;
        border-radius: 18px;
        border: 1px solid #eef1f5;
        box-shadow: 0 3px 12px rgba(15, 23, 42, 0.05);
        padding: 1.6rem 1.75rem;
        transition: box-shadow 0.2s ease, transform 0.2s ease;
        border-top: 4px solid transparent;
        display: flex;
        flex-direction: column;
        gap: 1.1rem;
        min-height: 230px;
    }
    .election-card:hover {
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.09);
        transform: translateY(-2px);
    }

    /* Top row: status pill + countdown pill */
    .card-top-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.6rem;
    }
    .status-large {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #15803d;
        background: rgba(34, 197, 94, 0.12);
        padding: 0.4rem 0.85rem 0.4rem 0.7rem;
        border-radius: 999px;
        line-height: 1;
    }
    .status-large .status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #22c55e;
        flex-shrink: 0;
    }
    .status-large.closed { color: #b91c1c; background: rgba(239, 68, 68, 0.1); }
    .status-large.closed .status-dot { background: #ef4444; }
    .status-large.paused { color: #92400e; background: rgba(245, 158, 11, 0.15); }
    .status-large.paused .status-dot { background: #f59e0b; }
    .status-large.scheduled { color: #3730a3; background: rgba(99, 102, 241, 0.12); }
    .status-large.scheduled .status-dot { background: #6366f1; }

    .remaining-time {
        display: inline-flex;
        align-items: baseline;
        gap: 0.4rem;
        font-size: 0.85rem;
        color: #64748b;
        background: #f1f5f9;
        padding: 0.45rem 0.9rem;
        border-radius: 999px;
        white-space: nowrap;
    }
    .remaining-time strong { color: #334155; font-weight: 600; font-size: 0.8rem; }
    .remaining-time span { color: #0f172a; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }

    .election-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        line-height: 1.3;
    }

    /* Status card */
    .status-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 0.85rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        flex-wrap: wrap;
    }
    .status-card .status-label { font-weight: 600; color: #64748b; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; }
    .status-card .status-value {
        font-weight: 600;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
    }
    .status-card .status-value.voted { color: #16a34a; background: rgba(34,197,94,0.12); }
    .status-card .status-value.not-voted { color: #dc2626; background: rgba(239,68,68,0.1); }
    .status-card .thank-you { font-size: 0.85rem; color: #16a34a; margin-left: auto; font-weight: 500; }

    /* ----- CARD BUTTONS – equal-width grid keeps them perfectly aligned ----- */
    .card-actions {
        margin-top: auto;
        padding-top: 0.25rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }
    .card-actions .btn { width: 100%; }

    /* ----- Modern button styles ----- */
    .btn {
        box-sizing: border-box;
        height: 46px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.9rem;
        font-family: inherit;
        line-height: 1;
        margin: 0;
        padding: 0 1.25rem;
        transition: background 0.2s, border-color 0.2s, transform 0.15s;
        border: 2px solid transparent;
        text-decoration: none;
        cursor: pointer;
        white-space: nowrap;
        -webkit-appearance: none;
        -moz-appearance: none;
        appearance: none;
        vertical-align: middle;
        touch-action: manipulation;
    }
    .btn:active { transform: scale(0.98); }
    /* Firefox adds an invisible inner border/padding to <button> only — this
    is what breaks alignment between a <button class="btn"> (e.g. a disabled
    "Vote Now") and an <a class="btn"> (e.g. "View Candidates") even though
    both share the exact same class. */
    button.btn::-moz-focus-inner {
        border: 0;
        padding: 0;
    }

    .btn-primary {
        background: #84cc16;
        color: #ffffff;
        border-color: #84cc16;
    }
    .btn-primary:hover { background: #65a30d; border-color: #65a30d; }
    .btn-primary:disabled {
        opacity: 0.55;
        cursor: not-allowed;
        background: #84cc16;
        border-color: #84cc16;
        color: #ffffff;
    }

    .btn-secondary {
        background: #ffffff;
        color: #1e293b;
        border-color: #e2e8f0;
    }
    .btn-secondary:hover { background: #f8fafc; border-color: #94a3b8; }

    /* ----- Form actions (vote page) ----- */
    .form-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
    }
    .form-actions .btn {
        flex: 1;
        text-align: center;
    }

    /* Candidate party / meta line inside the vote form */
    .candidate-meta { font-size: 0.78rem; color: #64748b; display: block; }
    .position-note { font-size: 0.78rem; color: #64748b; margin: -0.5rem 0 0.75rem; }

    /* Featured Candidates */
    .featured-section {
        margin-top: 2.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e2e8f0;
    }
    .featured-section .featured-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    .featured-section .featured-header h3 {
        font-size: 1.2rem;
        font-weight: 600;
        color: #0f172a;
        margin: 0;
    }
    .featured-carousel-viewport {
        position: relative;
        overflow: hidden;
        width: 100%;
        height: 300px;
        -webkit-mask-image: linear-gradient(to right, transparent 0%, black 10%, black 90%, transparent 100%);
                mask-image: linear-gradient(to right, transparent 0%, black 10%, black 90%, transparent 100%);
    }
    .featured-carousel-track {
        position: absolute;
        top: 50%;
        left: 0;
        display: flex;
        will-change: transform;
        transform: translateY(-50%);
    }
    .fc-card {
        flex: 0 0 auto;
        box-sizing: border-box;
        padding: 0 8px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        transform-origin: center center;
        will-change: transform;
    }
    .fc-card img {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        background: #f1f5f9;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        margin-bottom: 0.5rem;
        flex-shrink: 0;
    }
    .fc-card .fc-name {
        font-weight: 600;
        color: #0f172a;
        font-size: 0.85rem;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: block;
    }
    .fc-card .fc-position {
        font-size: 0.75rem;
        color: #64748b;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        display: block;
    }
    @media (max-width: 768px) {
        .featured-carousel-viewport {
            height: 220px;
            -webkit-mask-image: linear-gradient(to right, transparent 0%, black 14%, black 86%, transparent 100%);
                    mask-image: linear-gradient(to right, transparent 0%, black 14%, black 86%, transparent 100%);
        }
        .fc-card img { width: 60px; height: 60px; }
        .fc-card .fc-name { font-size: 0.78rem; }
        .fc-card .fc-position { font-size: 0.7rem; }
    }

    /* Other page styles */
    .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1.5rem; }
    .candidate-card {
        position: relative;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        padding: 3.25rem 1rem 1.25rem;
        text-align: center;
        margin-top: 45px; /* leaves room for the photo poking out above */
    }
    .candidate-card img {
        position: absolute;
        top: -45px;
        left: 50%;
        transform: translateX(-50%);
        width: 100px;
        height: 100px;
        object-fit: cover;
        border-radius: 50%;
        background: #f1f5f9;
        border: 4px solid #ffffff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.12);
    }
    .candidate-card h3 { margin: 0.5rem 0 0.25rem; font-size: 1rem; }
    .candidate-card .position { font-size: 0.85rem; color: #64748b; }
    .candidate-card .party { font-size: 0.78rem; color: #84cc16; font-weight: 600; }
    .candidate-card .details { font-size: 0.85rem; color: #334155; margin-top: 0.5rem; }
    .position-block { background: white; padding: 1rem 1.5rem 1.5rem; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
    .position-block legend { font-weight: 600; font-size: 1.1rem; padding: 0 0.5rem; }
    .candidate-list { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 0.5rem; }
    .candidate-option { display: flex; align-items: center; gap: 0.5rem; background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; cursor: pointer; transition: background 0.2s; min-height: 44px; }
    .candidate-option:hover { background: #e2e8f0; }
    .candidate-option input[type="radio"] { margin: 0; width: 20px; height: 20px; flex-shrink: 0; }
    .candidate-option img { width: 40px; height: 40px; object-fit: cover; border-radius: 50%; flex-shrink: 0; }
    .result-row { margin-bottom: 1rem; }
    .result-header { display: flex; justify-content: space-between; }
    .progress-bar-track { background: #e2e8f0; border-radius: 20px; height: 8px; overflow: hidden; margin-top: 0.25rem; }
    .progress-bar-fill { background: #84cc16; height: 100%; border-radius: 20px; }
    .results-block { margin-bottom: 2rem; }
    .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-error { background: #fee2e2; color: #991b1b; }
    .alert-info { background: #dbeafe; color: #1e40af; }
    .muted { color: #64748b; }
    .form-row { display: flex; flex-direction: column; gap: 0.35rem; }
    .form-row label { font-size: 0.82rem; font-weight: 600; color: #334155; }
    .form-row select { padding: 0.6rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; font-family: inherit; background: #fff; min-height: 44px; }
    .election-filter-row { max-width: 320px; margin-bottom: 1.5rem; }
    .election-filter-select { width: 100%; }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar { transform: translateX(-100%); width: 280px; padding-top: 0; }
        .sidebar.open { transform: translateX(0); }
        .main-content { margin-left: 0; padding: 1rem; }
        .page-header { flex-direction: row; align-items: center; background: white; box-shadow: 0 2px 4px rgba(0,0,0,0.05); padding: 0.5rem 1rem; margin-bottom: 1.5rem; border-bottom: none; border-radius: 0 0 8px 8px; }
        .page-header h1 { font-size: 1.2rem; }
        .page-header .date { font-size: 0.8rem; }
        .hamburger { display: flex; }
        .sidebar-overlay.active { display: block; opacity: 1; }
        .election-card { padding: 1.25rem; min-height: auto; }
        .card-top-row { flex-wrap: wrap; }
        .stats-bar { grid-template-columns: 1fr 1fr; }
        .card-actions { grid-template-columns: 1fr; }
        .form-actions { flex-wrap: wrap; }
        .form-actions .btn { flex: 1 1 100%; }
        .candidate-list { flex-direction: column; }
        .candidate-option { width: 100%; }
        .status-card { position: relative; }
        .status-card .thank-you { margin-left: 0; flex-basis: 100%; }
        .election-filter-row { max-width: none; }
        .election-filter-select { max-width: none !important; width: 100%; }
        .card-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
    }

    @media (max-width: 420px) {
        .stats-bar { grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .stat-box { padding: 0.85rem 0.9rem; }
        .stat-box .stat-number { font-size: 1.6rem; }
        .page-header h1 { font-size: 1.05rem; }
        .election-title { font-size: 1.2rem; }
        .status-large { font-size: 0.72rem; padding: 0.35rem 0.7rem 0.35rem 0.6rem; }
        .remaining-time { font-size: 0.78rem; padding: 0.4rem 0.75rem; }
        .featured-carousel-viewport { height: 190px; }
        .fc-card img { width: 52px; height: 52px; }
        .candidate-card { padding: 2.75rem 0.75rem 1rem; }
        .candidate-card img { width: 80px; height: 80px; top: -40px; }
    }

    /* ================= BALLOT VOTING EXPERIENCE ================= */
    .btn-block { width: 100%; }

    .ballot-shell { max-width: 720px; margin: 0 auto; }

    .ballot-header { margin-bottom: 1.5rem; }
    .ballot-header-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
    .ballot-title { margin: 0; font-size: 1.3rem; color: #0f172a; }
    .ballot-exit { font-size: 0.85rem; font-weight: 600; color: #dc2626; text-decoration: none; padding: 0.4rem 0.8rem; border-radius: 8px; transition: background 0.2s; flex-shrink: 0; }
    .ballot-exit:hover { background: rgba(239,68,68,0.08); }

    .ballot-progress-track { height: 10px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
    .ballot-progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #84cc16, #65a30d); border-radius: 999px; transition: width 0.35s ease; }
    .ballot-progress-label { font-size: 0.85rem; color: #64748b; font-weight: 600; margin-top: 0.5rem; }

    .ballot-dots { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.75rem; }
    .ballot-dot {
        width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0;
        transition: background 0.2s, transform 0.2s;
    }
    .ballot-dot.done { background: #84cc16; }
    .ballot-dot.active { background: #1e293b; transform: scale(1.3); }

    .ballot-step { display: none; }
    .ballot-step.active { display: block; animation: ballotFadeIn 0.25s ease; }
    @keyframes ballotFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

    .ballot-step-heading { margin-bottom: 1.25rem; }
    .ballot-step-eyebrow { display: inline-block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #65a30d; background: rgba(132,204,22,0.14); padding: 0.25rem 0.65rem; border-radius: 999px; margin-bottom: 0.6rem; }
    .ballot-position-title { margin: 0 0 0.3rem; font-size: 1.5rem; color: #0f172a; }
    .ballot-instruction { margin: 0.35rem 0 0; color: #64748b; font-size: 0.9rem; }

    .ballot-candidates { border: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 1rem; }

    .ballot-candidate {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        background: #fff;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        padding: 1.1rem 1.25rem;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s, opacity 0.25s, filter 0.25s, transform 0.1s;
    }
    .ballot-candidate:active { transform: scale(0.995); }
    .ballot-candidate:hover { border-color: #84cc16; }
    .ballot-candidate.selected { border-color: #1e293b; background: #f8fafc; box-shadow: 0 6px 18px rgba(15,23,42,0.1); }
    .ballot-candidate.dimmed { opacity: 0.4; filter: grayscale(75%); }
    .ballot-candidate.dimmed:hover { border-color: #e2e8f0; }

    /* Radio input kept in DOM (accessible/focusable) but visually replaced by the ballot bubble */
    .ballot-radio-input {
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
        overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
    }
    .ballot-radio-input:focus-visible ~ .ballot-bubble { outline: 3px solid #84cc16; outline-offset: 2px; }

    /* The bubble itself: outlined ring with a fill that, once checked, covers the
       entire interior with no gap — like a properly-shaded ballot oval. */
    .ballot-bubble {
        flex-shrink: 0;
        width: 28px; height: 28px;
        border-radius: 50%;
        border: 2.5px solid #64748b;
        box-sizing: border-box;
        padding: 2px;
        display: block;
        margin-top: 0.15rem;
        transition: border-color 0.2s;
    }
    .ballot-bubble-fill { display: block; width: 100%; height: 100%; border-radius: 50%; background: transparent; transition: background-color 0.15s ease; }
    .ballot-radio-input:checked ~ .ballot-bubble { border-color: #1e293b; }
    .ballot-radio-input:checked ~ .ballot-bubble .ballot-bubble-fill { background-color: #1e293b; }

    .candidate-avatar {
        width: 64px; height: 64px; border-radius: 50%; object-fit: cover; background: #f1f5f9;
        border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.1); flex-shrink: 0;
    }
    .ballot-candidate-body { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; flex: 1; }
    .ballot-candidate-name { font-weight: 700; font-size: 1.05rem; color: #0f172a; }
    .ballot-candidate-party { font-size: 0.8rem; font-weight: 600; color: #65a30d; }
    .ballot-candidate-meta { display: flex; flex-wrap: wrap; gap: 0.5rem; font-size: 0.78rem; color: #64748b; }
    .ballot-candidate-meta span:not(:last-child)::after { content: '·'; margin-left: 0.5rem; color: #cbd5e1; }
    .ballot-candidate-platform { font-size: 0.82rem; color: #334155; margin-top: 0.35rem; line-height: 1.45; }

    .ballot-nav { display: flex; justify-content: space-between; gap: 1rem; margin-top: 1.75rem; }
    .ballot-nav .btn { min-width: 130px; }

    /* Review step — styled like an actual paper ballot receipt */
    .ballot-summary-sheet {
        background: #fff;
        border: 2px dashed #cbd5e1;
        border-radius: 16px;
        padding: 1.5rem 1.5rem 0.5rem;
    }
    .ballot-summary-sheet-header { text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 1rem; margin-bottom: 1rem; }
    .ballot-summary-sheet-title { font-weight: 800; font-size: 1.1rem; color: #0f172a; letter-spacing: 0.01em; }
    .ballot-summary-sheet-sub { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-top: 0.2rem; }

    .ballot-summary-row {
        display: flex; align-items: center; gap: 0.9rem;
        padding: 0.9rem 0; border-bottom: 1px dashed #e2e8f0;
    }
    .ballot-summary-row:last-child { border-bottom: none; }
    .ballot-summary-photo { width: 46px; height: 46px; border-radius: 50%; object-fit: cover; background: #f1f5f9; flex-shrink: 0; }
    .ballot-summary-text { flex: 1; min-width: 0; }
    .ballot-summary-position { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
    .ballot-summary-name { font-weight: 700; color: #0f172a; font-size: 0.95rem; }
    .ballot-summary-name.none { color: #b91c1c; font-weight: 600; font-style: italic; }
    .ballot-summary-party { font-size: 0.78rem; color: #65a30d; font-weight: 600; }
    .ballot-summary-change { flex-shrink: 0; border: none; background: #f1f5f9; color: #1e293b; font-size: 0.78rem; font-weight: 600; padding: 0.4rem 0.8rem; border-radius: 999px; cursor: pointer; }
    .ballot-summary-change:hover { background: #e2e8f0; }

    /* Success modal */
    .ballot-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.55);
        z-index: 2000; align-items: center; justify-content: center; padding: 1.25rem;
    }
    .ballot-modal-overlay.active { display: flex; }
    .ballot-modal-card {
        background: #fff; border-radius: 20px; padding: 2.25rem 2rem; max-width: 380px; width: 100%;
        text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        animation: ballotModalPop 0.3s ease;
    }
    @keyframes ballotModalPop { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
    .ballot-modal-check {
        width: 64px; height: 64px; border-radius: 50%; background: #84cc16; color: #fff;
        display: flex; align-items: center; justify-content: center; font-size: 2rem;
        margin: 0 auto 1rem;
    }
    .ballot-modal-card h2 { margin: 0 0 0.5rem; color: #0f172a; }
    .ballot-modal-card p { color: #64748b; font-size: 0.92rem; margin: 0 0 1.5rem; }

    @media (max-width: 640px) {
        .ballot-candidate { flex-wrap: wrap; }
        .ballot-candidate-body { flex-basis: 100%; }
        .ballot-nav { flex-direction: column-reverse; }
        .ballot-nav .btn { width: 100%; min-width: 0; }
        .ballot-title { font-size: 1.1rem; }
        .ballot-position-title { font-size: 1.25rem; }
        .candidate-avatar { width: 52px; height: 52px; }
    }
    </style>
    </head>
    <body>
    <!-- Sidebar overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo-area">
            <img src="assets/logo.png" alt="FICT Logo">
            <h2>Election</h2>
        </div>
        <nav>
            <a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">Dashboard</a>
            <a href="?page=vote" class="<?= $page === 'vote' ? 'active' : '' ?>">Vote</a>
            <a href="?page=candidates" class="<?= $page === 'candidates' ? 'active' : '' ?>">View Candidate Profile</a>
            <a href="?page=results" class="<?= $page === 'results' ? 'active' : '' ?>">Results</a>
        </nav>
        <div class="profile-section">
            <div class="avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= h($user['name']) ?></div>
                <div class="role"><?= h($user['department'] ?: 'Student') ?><?= $user['year_level'] ? ' · ' . h($user['year_level']) : '' ?></div>
            </div>
        </div>
        <div class="logout-link">
            <a href="logout.php">Log Out</a>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">☰</button>
                <h1>Welcome, <?= h($user['name']) ?></h1>
            </div>
            <span class="date"><?= date('l, F j, Y') ?></span>
        </div>

        <?php if ($page === 'home'): ?>
            <!-- Dashboard Home -->

            <?php
                $now = new DateTime();
                $active = 0;
                $completed = 0;
                foreach ($electionData as $data) {
                    [$isOpen] = electionUiStatus($data['config'], $now);
                    if ($isOpen) $active++;
                    else $completed++;
                }
            ?>
            <div class="stats-bar">
                <div class="stat-box">
                    <div class="stat-number"><?= $active ?></div>
                    <div class="stat-label">Active Elections</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?= $completed ?></div>
                    <div class="stat-label">Completed Elections</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?= count($electionData) ?></div>
                    <div class="stat-label">Total Elections</div>
                </div>
            </div>

            <h2 class="section-title">Your Elections</h2>

            <?php if (empty($electionData)): ?>
                <div class="alert alert-info">No elections have been set up for you yet. Check back soon.</div>
            <?php endif; ?>

            <div class="election-cards">
                <?php foreach ($electionData as $eid => $data):
                    $config = $data['config'];
                    [$isOpen, $statusText, $statusClass, $start, $end] = electionUiStatus($config, $now);
                    $remaining = '';
                    if ($isOpen) {
                        $diff = $end->getTimestamp() - $now->getTimestamp();
                        if ($diff > 0) {
                            $hours = floor($diff / 3600);
                            $minutes = floor(($diff % 3600) / 60);
                            $seconds = $diff % 60;
                            $remaining = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                        } else {
                            $remaining = '00:00:00';
                        }
                    }
                    $canVote = $isOpen && !$data['hasVoted'] && $data['totalPositions'] > 0;
                    $accentColor = $config['accent'];
                ?>
                    <div class="election-card" style="border-top-color: <?= $accentColor ?>;">
                        <div class="card-top-row">
                            <div class="status-large <?= $statusClass ?>">
                                <span class="status-dot"></span>
                                <?= $statusText ?>
                            </div>
                            <?php if ($isOpen): ?>
                                <div class="remaining-time"><strong>Ends in:</strong> <span id="countdown_<?= $eid ?>"><?= $remaining ?></span></div>
                            <?php elseif ($now < $start): ?>
                                <div class="remaining-time">Starts on <?= date('M j, Y g:i A', $start->getTimestamp()) ?></div>
                            <?php else: ?>
                                <div class="remaining-time">This election has ended.</div>
                            <?php endif; ?>
                        </div>
                        <h2 class="election-title"><?= h($config['name']) ?></h2>
                        <div class="status-card">
                            <span class="status-label">Voting Status</span>
                            <span class="status-value <?= $data['hasVoted'] ? 'voted' : 'not-voted' ?>">
                                <?= $data['hasVoted'] ? '✔ Vote Submitted' : '❌ Not Submitted' ?>
                            </span>
                            <?php if ($data['hasVoted']): ?>
                                <span class="thank-you">Thank you for participating.</span>
                            <?php endif; ?>
                        </div>
                        <!-- ⬇️ BUTTON ROW – margin-top: auto pushes it to bottom -->
                        <div class="card-actions">
                            <a href="?page=candidates&election_id=<?= $eid ?>" class="btn btn-secondary">View Candidates</a>
                            <?php if ($canVote): ?>
                                <a href="?page=vote&election_id=<?= $eid ?>" class="btn btn-primary">Vote Now</a>
                            <?php else: ?>
                                <button class="btn btn-primary" disabled>Vote Now</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <script>
            // Countdown timers
            <?php foreach ($electionData as $eid => $data):
                $end = new DateTime($data['config']['end_date']);
                $endTimestamp = $end->getTimestamp();
            ?>
                (function() {
                    const endTime = <?= $endTimestamp ?> * 1000;
                    const el = document.getElementById('countdown_<?= $eid ?>');
                    if (!el) return;
                    function update() {
                        const now = Date.now();
                        const diff = endTime - now;
                        if (diff <= 0) {
                            el.textContent = '00:00:00';
                            return;
                        }
                        const hours = Math.floor(diff / (1000 * 60 * 60));
                        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                        el.textContent = 
                            String(hours).padStart(2, '0') + ':' +
                            String(minutes).padStart(2, '0') + ':' +
                            String(seconds).padStart(2, '0');
                    }
                    update();
                    setInterval(update, 1000);
                })();
            <?php endforeach; ?>
            </script>

            <!-- Featured Candidates -->
            <?php
                $featured = [];
                foreach ($electionData as $data) {
                    foreach ($data['positions'] as $pos) {
                        $cands = $data['candidatesByPosition'][$pos['position_id']] ?? [];
                        foreach ($cands as $c) {
                            $featured[] = ['candidate' => $c, 'position' => $pos['title']];
                        }
                    }
                }
                shuffle($featured);
                $featured = array_slice($featured, 0, 12); // carousel needs more than 3 to loop nicely
            ?>
            <?php if (!empty($featured)): ?>
            <div class="featured-section">
                <div class="featured-header">
                    <h3>Featured Candidates</h3>
                    <a href="?page=candidates" class="btn btn-secondary" style="padding:0.3rem 1rem; font-size:0.85rem;">View All</a>
                </div>
                <div class="featured-carousel-viewport" id="featuredViewport">
                    <div class="featured-carousel-track" id="featuredTrack"></div>
                </div>
            </div>
            <script>
                const featuredCandidates = <?= json_encode(array_map(function ($item) {
                    $c = $item['candidate'];
                    return [
                        'name' => $c['name'],
                        'position' => $item['position'],
                        'photo' => !empty($c['photo']) ? $c['photo'] : 'assets/default-avatar.png',
                    ];
                }, $featured)) ?>;
            </script>
            <?php endif; ?>

        <?php elseif ($page === 'vote'): ?>
            <!-- Vote page: show election cards if no election_id, else show voting form -->
            <?php $now = new DateTime(); ?>
            <?php if (!$selectedElectionId): ?>
                <h2>Choose an Election to Vote In</h2>
                <?php if (empty($electionData)): ?>
                    <div class="alert alert-info">No elections are available to you right now.</div>
                <?php endif; ?>
                <div class="election-cards">
                    <?php foreach ($electionData as $eid => $data):
                        $config = $data['config'];
                        [$isOpen, $statusText, $statusClass, $start, $end] = electionUiStatus($config, $now);
                        $remaining = '';
                        if ($isOpen) {
                            $diff = $end->getTimestamp() - $now->getTimestamp();
                            if ($diff > 0) {
                                $hours = floor($diff / 3600);
                                $minutes = floor(($diff % 3600) / 60);
                                $seconds = $diff % 60;
                                $remaining = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                            } else {
                                $remaining = '00:00:00';
                            }
                        }
                        $canVote = $isOpen && !$data['hasVoted'] && $data['totalPositions'] > 0;
                        $accentColor = $config['accent'];
                    ?>
                        <div class="election-card" style="border-top-color: <?= $accentColor ?>;">
                            <div class="card-top-row">
                                <div class="status-large <?= $statusClass ?>">
                                    <span class="status-dot"></span>
                                    <?= $statusText ?>
                                </div>
                                <?php if ($isOpen): ?>
                                    <div class="remaining-time"><strong>Ends in:</strong> <span id="countdown_vote_<?= $eid ?>"><?= $remaining ?></span></div>
                                <?php elseif ($now < $start): ?>
                                    <div class="remaining-time">Starts on <?= date('M j, Y g:i A', $start->getTimestamp()) ?></div>
                                <?php else: ?>
                                    <div class="remaining-time">This election has ended.</div>
                                <?php endif; ?>
                            </div>
                            <h2 class="election-title"><?= h($config['name']) ?></h2>
                            <div class="status-card">
                                <span class="status-label">Voting Status</span>
                                <span class="status-value <?= $data['hasVoted'] ? 'voted' : 'not-voted' ?>">
                                    <?= $data['hasVoted'] ? '✔ Vote Submitted' : '❌ Not Submitted' ?>
                                </span>
                                <?php if ($data['hasVoted']): ?>
                                    <span class="thank-you">Thank you for participating.</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-actions">
                                <a href="?page=candidates&election_id=<?= $eid ?>" class="btn btn-secondary">View Candidates</a>
                                <?php if ($canVote): ?>
                                    <a href="?page=vote&election_id=<?= $eid ?>" class="btn btn-primary">Vote Now</a>
                                <?php else: ?>
                                    <button class="btn btn-primary" disabled>Vote Now</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <script>
                // Countdown for vote page cards
                <?php foreach ($electionData as $eid => $data):
                    $end = new DateTime($data['config']['end_date']);
                    $endTimestamp = $end->getTimestamp();
                ?>
                    (function() {
                        const endTime = <?= $endTimestamp ?> * 1000;
                        const el = document.getElementById('countdown_vote_<?= $eid ?>');
                        if (!el) return;
                        function update() {
                            const now = Date.now();
                            const diff = endTime - now;
                            if (diff <= 0) {
                                el.textContent = '00:00:00';
                                return;
                            }
                            const hours = Math.floor(diff / (1000 * 60 * 60));
                            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
                            el.textContent = 
                                String(hours).padStart(2, '0') + ':' +
                                String(minutes).padStart(2, '0') + ':' +
                                String(seconds).padStart(2, '0');
                        }
                        update();
                        setInterval(update, 1000);
                    })();
                <?php endforeach; ?>
                </script>
            <?php else:
                // Voting form for a specific election
                if (!isset($electionData[$selectedElectionId])) {
                    echo '<div class="alert alert-error">Invalid election.</div>';
                } else {
                    $data = $electionData[$selectedElectionId];
                    $config = $data['config'];
                    $positions = $data['positions'];
                    $candidatesByPosition = $data['candidatesByPosition'];

                    [$isOpen] = electionUiStatus($config, $now);

                    if ($data['hasVoted']) {
                        echo '<div class="alert alert-info">You have already voted in this election.</div>';
                    } elseif (!$isOpen) {
                        echo '<div class="alert alert-error">This election is not currently open.</div>';
                    } elseif (empty($positions)) {
                        echo '<div class="alert alert-info">No positions are available to you in this election yet.</div>';
                    } else {
            ?>
                        <?php $totalPositions = count($positions); ?>
                        <div class="ballot-shell">
                            <div class="ballot-header">
                                <div class="ballot-header-top">
                                    <h2 class="ballot-title"><?= h($config['name']) ?></h2>
                                    <a href="?page=vote" class="ballot-exit" onclick="return confirmBallotExit();">Exit</a>
                                </div>
                                <div class="ballot-progress-track">
                                    <div class="ballot-progress-fill" id="ballotProgressFill"></div>
                                </div>
                                <div class="ballot-progress-label" id="ballotProgressLabel">Position 1 of <?= $totalPositions ?></div>
                                <div class="ballot-dots" id="ballotDots"></div>
                            </div>

                            <div id="statusMessage" class="alert" style="display:none;"></div>

                            <form id="voteForm" data-election="<?= $selectedElectionId ?>" data-total-positions="<?= $totalPositions ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="election_id" value="<?= $selectedElectionId ?>">

                                <?php foreach ($positions as $index => $position): ?>
                                    <?php $candidates = $candidatesByPosition[$position['position_id']] ?? []; ?>
                                    <div class="ballot-step<?= $index === 0 ? ' active' : '' ?>" data-step="<?= $index ?>" data-kind="position" data-position-title="<?= h($position['title']) ?>">
                                        <div class="ballot-step-heading">
                                            <span class="ballot-step-eyebrow">Position <?= $index + 1 ?> of <?= $totalPositions ?></span>
                                            <h3 class="ballot-position-title"><?= h($position['title']) ?></h3>
                                            <?php if (!empty($position['year_restriction'])): ?>
                                                <p class="position-note">Only visible to <?= h($position['year_restriction']) ?> students.</p>
                                            <?php endif; ?>
                                            <p class="ballot-instruction">Select one candidate, then continue.</p>
                                        </div>

                                        <?php if (empty($candidates)): ?>
                                            <p class="muted">No candidates registered for this position.</p>
                                        <?php else: ?>
                                            <fieldset class="ballot-candidates">
                                                <?php foreach ($candidates as $candidate):
                                                    $partyLabel = (!empty($candidate['party']) && $candidate['party'] !== 'No Party / Independent') ? $candidate['party'] : 'Independent';
                                                ?>
                                                    <label class="ballot-candidate">
                                                        <input type="radio" class="ballot-radio-input" name="position_<?= (int) $position['position_id'] ?>" value="<?= (int) $candidate['id'] ?>" data-candidate-name="<?= h($candidate['name']) ?>" data-candidate-photo="<?= h(!empty($candidate['photo']) ? $candidate['photo'] : 'assets/default-avatar.png') ?>">
                                                        <span class="ballot-bubble" aria-hidden="true"><span class="ballot-bubble-fill"></span></span>
                                                        <img class="candidate-avatar" src="<?= h(!empty($candidate['photo']) ? $candidate['photo'] : 'assets/default-avatar.png') ?>" alt="">
                                                        <span class="ballot-candidate-body">
                                                            <span class="ballot-candidate-name"><?= h($candidate['name']) ?></span>
                                                            <span class="ballot-candidate-party"><?= h($partyLabel) ?></span>
                                                            <span class="ballot-candidate-meta">
                                                                <?php if (!empty($candidate['course'])): ?><span><?= h($candidate['course']) ?></span><?php endif; ?>
                                                                <?php if (!empty($candidate['year_level'])): ?><span><?= h($candidate['year_level']) ?></span><?php endif; ?>
                                                            </span>
                                                            <?php if (!empty($candidate['platform'])): ?>
                                                                <span class="ballot-candidate-platform"><strong>Platform:</strong> <?= h($candidate['platform']) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                        <?php endif; ?>

                                        <div class="ballot-nav">
                                            <button type="button" class="btn btn-secondary" onclick="ballotPrev()" <?= $index === 0 ? 'style="visibility:hidden;"' : '' ?>>&larr; Back</button>
                                            <button type="button" class="btn btn-primary" onclick="ballotNext()">Continue &rarr;</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <div class="ballot-step" data-step="<?= $totalPositions ?>" data-kind="review">
                                    <div class="ballot-step-heading">
                                        <span class="ballot-step-eyebrow">Final Step</span>
                                        <h3 class="ballot-position-title">Review Your Ballot</h3>
                                        <p class="ballot-instruction">Double-check your choices below. You can go back to change any selection before submitting.</p>
                                    </div>

                                    <div class="ballot-summary-sheet">
                                        <div class="ballot-summary-sheet-header">
                                            <div class="ballot-summary-sheet-title"><?= h($config['name']) ?></div>
                                            <div class="ballot-summary-sheet-sub">Official Ballot Summary</div>
                                        </div>
                                        <div id="ballotSummaryList" class="ballot-summary-list"></div>
                                    </div>

                                    <div class="ballot-nav">
                                        <button type="button" class="btn btn-secondary" onclick="ballotPrev()">&larr; Back</button>
                                        <button type="submit" class="btn btn-primary" id="submitBtn">Submit My Vote</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Submission success modal -->
                        <div class="ballot-modal-overlay" id="ballotSuccessModal">
                            <div class="ballot-modal-card">
                                <div class="ballot-modal-check">✔</div>
                                <h2>Vote Submitted!</h2>
                                <p id="ballotModalMessage">Thank you for participating in the election. Your voice has been counted.</p>
                                <button type="button" class="btn btn-primary btn-block" onclick="closeBallotModal()">Back to Dashboard</button>
                            </div>
                        </div>
            <?php
                    }
                }
            endif; ?>

        <?php elseif ($page === 'candidates'): ?>
            <!-- Candidate Profiles -->
            <h2>All Candidates</h2>
            <?php
                $filterEid = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
                $displayElections = $filterEid ? array_intersect_key($electionData, [$filterEid => true]) : $electionData;
            ?>
            <?php if (count($electionData) > 1): ?>
                <div class="form-row election-filter-row">
                    <label for="candidateElectionFilter">Election</label>
                    <select id="candidateElectionFilter" class="election-filter-select" onchange="window.location.href = this.value;">
                        <option value="?page=candidates" <?= !$filterEid ? 'selected' : '' ?>>All Elections</option>
                        <?php foreach ($electionData as $eid => $data): ?>
                            <option value="?page=candidates&election_id=<?= $eid ?>" <?= $filterEid === $eid ? 'selected' : '' ?>><?= h($data['config']['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <?php
                $hasAny = false;
                foreach ($displayElections as $eid => $data):
                    $positions = $data['positions'];
                    if (empty($positions)) continue;
                    $hasAny = true;
                    $config = $data['config'];
                    $subtitle = $config['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government — ' . $config['department'];
            ?>
                    <h3 style="margin-bottom:0.15rem;"><?= h($config['name']) ?></h3>
                    <p class="muted" style="margin:0 0 1rem;font-size:0.85rem;"><?= h($subtitle) ?></p>
                    <div class="card-grid">
                    <?php foreach ($positions as $position):
                        $candidates = $data['candidatesByPosition'][$position['position_id']] ?? [];
                        foreach ($candidates as $candidate):
                    ?>
                            <div class="candidate-card">
                                <?php if (!empty($candidate['photo'])): ?>
                                    <img src="<?= h($candidate['photo']) ?>" alt="<?= h($candidate['name']) ?>">
                                <?php else: ?>
                                    <img src="assets/default-avatar.png" alt="No photo">
                                <?php endif; ?>
                                <h3><?= h($candidate['name']) ?></h3>
                                <div class="position"><?= h($position['title']) ?></div>
                                <?php if (!empty($candidate['party']) && $candidate['party'] !== 'No Party / Independent'): ?>
                                    <div class="party"><?= h($candidate['party']) ?></div>
                                <?php endif; ?>
                                <div class="details">
                                    <?php if (!empty($candidate['course'])): ?><div><?= h($candidate['course']) ?></div><?php endif; ?>
                                    <?php if (!empty($candidate['year_level'])): ?><div><?= h($candidate['year_level']) ?></div><?php endif; ?>
                                    <?php if (!empty($candidate['platform'])): ?><div><strong>Platform:</strong> <?= h($candidate['platform']) ?></div><?php endif; ?>
                                </div>
                            </div>
                    <?php endforeach; endforeach; ?>
                    </div>
            <?php endforeach;
            if (!$hasAny) echo '<p>No candidates found.</p>';
            ?>

        <?php elseif ($page === 'results'): ?>
            <!-- Results page -->
            <h2>Live Results</h2>
            <p class="muted">Results update automatically every 15 seconds.</p>
            <?php
                $anyVisible = false;
                foreach ($electionData as $eid => $data):
                    $config = $data['config'];
                    $visible = $config['results_visibility'] === 'always'
                        || ($config['results_visibility'] === 'after' && in_array($config['status'], ['closed', 'archived'], true));
                    if (!$visible || empty($data['positions'])) continue;
                    $anyVisible = true;
            ?>
                <h2><?= h($config['name']) ?></h2>
                <?php foreach ($data['positions'] as $position):
                    $candidates = $data['candidatesByPosition'][$position['position_id']] ?? [];
                    $totalVotes = array_sum(array_column($candidates, 'vote_count'));
                ?>
                    <div class="results-block">
                        <h3><?= h($position['title']) ?></h3>
                        <?php if (empty($candidates)): ?>
                            <p class="muted">No candidates.</p>
                        <?php else: ?>
                            <?php foreach ($candidates as $candidate):
                                $voteCount = (int) $candidate['vote_count'];
                                $percentage = $totalVotes > 0 ? round(($voteCount / $totalVotes) * 100, 1) : 0;
                            ?>
                                <div class="result-row">
                                    <div class="result-header">
                                        <span class="candidate-name"><?= h($candidate['name']) ?></span>
                                        <span class="vote-count"><?= $voteCount ?> vote<?= $voteCount === 1 ? '' : 's' ?> (<?= $percentage ?>%)</span>
                                    </div>
                                    <div class="progress-bar-track">
                                        <div class="progress-bar-fill" style="width: <?= $percentage ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <p class="muted">Total votes: <?= (int) $totalVotes ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <hr>
            <?php endforeach;
            if (!$anyVisible) echo '<div class="alert alert-info">Results are not available yet.</div>';
            ?>
        <?php endif; ?>
    </div>

    <!-- Hamburger toggle & Vote form AJAX -->
    <script>
    const hamburger = document.getElementById('hamburgerBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    function toggleSidebar(open) {
        if (open === undefined) open = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open', open);
        overlay.classList.toggle('active', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }
    hamburger.addEventListener('click', () => toggleSidebar());
    overlay.addEventListener('click', () => toggleSidebar(false));
    window.addEventListener('resize', () => { if (window.innerWidth > 768) toggleSidebar(false); });

    // ================= BALLOT VOTING EXPERIENCE =================
    (function () {
        const voteForm = document.getElementById('voteForm');
        if (!voteForm) return;

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
        }

        const steps = Array.from(voteForm.querySelectorAll('.ballot-step'));
        const totalPositions = parseInt(voteForm.dataset.totalPositions || '0', 10);
        let currentStep = 0;

        const progressFill = document.getElementById('ballotProgressFill');
        const progressLabel = document.getElementById('ballotProgressLabel');
        const dotsWrap = document.getElementById('ballotDots');
        const summaryList = document.getElementById('ballotSummaryList');
        const statusMessage = document.getElementById('statusMessage');
        const submitBtn = document.getElementById('submitBtn');
        const modal = document.getElementById('ballotSuccessModal');

        if (dotsWrap) {
            dotsWrap.innerHTML = steps.map((s, i) => `<span class="ballot-dot" data-dot="${i}"></span>`).join('');
        }
        const dots = dotsWrap ? Array.from(dotsWrap.children) : [];

        function updateDimming(fieldset) {
            if (!fieldset) return;
            const labels = Array.from(fieldset.querySelectorAll('.ballot-candidate'));
            const anyChecked = labels.some(l => l.querySelector('.ballot-radio-input').checked);
            labels.forEach(label => {
                const input = label.querySelector('.ballot-radio-input');
                label.classList.toggle('selected', input.checked);
                label.classList.toggle('dimmed', anyChecked && !input.checked);
            });
        }

        // Wire up per-position dimming behavior: picking a candidate shades
        // their bubble solid and visually fades out the rest of the field.
        steps.forEach(step => {
            const fieldset = step.querySelector('.ballot-candidates');
            if (!fieldset) return;
            fieldset.addEventListener('change', () => updateDimming(fieldset));
            updateDimming(fieldset);
        });

        function updateProgress() {
            const pct = ((currentStep + 1) / steps.length) * 100;
            if (progressFill) progressFill.style.width = pct + '%';
            const step = steps[currentStep];
            if (progressLabel) {
                progressLabel.textContent = step.dataset.kind === 'review'
                    ? 'Review & Submit'
                    : `Position ${currentStep + 1} of ${totalPositions}`;
            }
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === currentStep);
                dot.classList.toggle('done', i < currentStep);
            });
        }

        function buildSummary() {
            if (!summaryList) return;
            const positionSteps = steps.filter(s => s.dataset.kind === 'position');
            summaryList.innerHTML = positionSteps.map(step => {
                const title = step.dataset.positionTitle || '';
                const stepIndex = steps.indexOf(step);
                const checked = step.querySelector('.ballot-radio-input:checked');
                if (!checked) {
                    return `
                    <div class="ballot-summary-row">
                        <div class="ballot-summary-text">
                            <div class="ballot-summary-position">${escHtml(title)}</div>
                            <div class="ballot-summary-name none">No selection</div>
                        </div>
                        ${step.querySelector('.ballot-candidates') ? `<button type="button" class="ballot-summary-change" onclick="ballotGoTo(${stepIndex})">Select</button>` : ''}
                    </div>`;
                }
                const name = checked.dataset.candidateName || '';
                const photo = checked.dataset.candidatePhoto || 'assets/default-avatar.png';
                const partyEl = checked.closest('.ballot-candidate').querySelector('.ballot-candidate-party');
                const party = partyEl ? partyEl.textContent : '';
                return `
                <div class="ballot-summary-row">
                    <img class="ballot-summary-photo" src="${escHtml(photo)}" alt="">
                    <div class="ballot-summary-text">
                        <div class="ballot-summary-position">${escHtml(title)}</div>
                        <div class="ballot-summary-name">${escHtml(name)}</div>
                        <div class="ballot-summary-party">${escHtml(party)}</div>
                    </div>
                    <button type="button" class="ballot-summary-change" onclick="ballotGoTo(${stepIndex})">Change</button>
                </div>`;
            }).join('');
        }

        function showStep(index) {
            steps.forEach((s, i) => s.classList.toggle('active', i === index));
            currentStep = index;
            updateProgress();
            if (steps[index].dataset.kind === 'review') buildSummary();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function currentStepValid() {
            const step = steps[currentStep];
            if (step.dataset.kind === 'review') return true;
            const fieldset = step.querySelector('.ballot-candidates');
            if (!fieldset) return true; // nothing to select — nothing to require
            return !!fieldset.querySelector('.ballot-radio-input:checked');
        }

        window.ballotNext = function () {
            if (!currentStepValid()) {
                if (statusMessage) {
                    statusMessage.style.display = 'block';
                    statusMessage.className = 'alert alert-error';
                    statusMessage.textContent = 'Please select a candidate before continuing.';
                }
                return;
            }
            if (statusMessage) statusMessage.style.display = 'none';
            if (currentStep < steps.length - 1) showStep(currentStep + 1);
        };

        window.ballotPrev = function () {
            if (statusMessage) statusMessage.style.display = 'none';
            if (currentStep > 0) showStep(currentStep - 1);
        };

        window.ballotGoTo = function (index) {
            if (statusMessage) statusMessage.style.display = 'none';
            showStep(index);
        };

        window.confirmBallotExit = function () {
            const anySelected = voteForm.querySelector('.ballot-radio-input:checked');
            if (anySelected) {
                return confirm("You have selections on this ballot that haven't been submitted. Leave without voting?");
            }
            return true;
        };

        window.closeBallotModal = function () {
            window.location.href = '?page=home';
        };

        showStep(0);

        voteForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!submitBtn) return;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';
            if (statusMessage) statusMessage.style.display = 'none';

            const formData = new FormData(voteForm);
            try {
                const response = await fetch('vote.php', {
                    method: 'POST',
                    body: formData,
                });
                const data = await response.json();
                if (data.success) {
                    voteForm.querySelectorAll('input, button').forEach(el => el.disabled = true);
                    if (modal) {
                        const msgEl = document.getElementById('ballotModalMessage');
                        if (msgEl && data.message) msgEl.textContent = data.message;
                        modal.classList.add('active');
                    }
                } else {
                    if (statusMessage) {
                        statusMessage.style.display = 'block';
                        statusMessage.className = 'alert alert-error';
                        statusMessage.textContent = data.message || 'Something went wrong. Please try again.';
                    }
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit My Vote';
                }
            } catch (err) {
                if (statusMessage) {
                    statusMessage.style.display = 'block';
                    statusMessage.className = 'alert alert-error';
                    statusMessage.textContent = 'Network error. Please try again.';
                }
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit My Vote';
            }
        });
    })();
    (function () {
    function escapeHtml(str){
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }
    const viewport = document.getElementById('featuredViewport');
    const track = document.getElementById('featuredTrack');
    if (!viewport || !track || typeof featuredCandidates === 'undefined' || featuredCandidates.length === 0) return;

    const MIN_SCALE = 0.72;
    const MAX_SCALE = 1.28;
    const BASE_SPEED = 40;
    const SPEED_VARIANCE = 0.85;

    const setLength = featuredCandidates.length;
    const renderList = [...featuredCandidates, ...featuredCandidates, ...featuredCandidates];

    track.innerHTML = renderList.map(c => `
        <div class="fc-card">
            <img src="${escapeHtml(c.photo)}" alt="${escapeHtml(c.name)}">
            <div class="fc-name">${escapeHtml(c.name)}</div>
            <div class="fc-position">${escapeHtml(c.position)}</div>
        </div>
    `).join('');

    const cards = Array.from(track.children);
    let cardWidth = 0;
    let trackPosition = 0;
    let paused = false;
    let lastTime = null;
    let VISIBLE_COUNT = window.innerWidth <= 768 ? 3 : 5;

    function measure() {
        VISIBLE_COUNT = window.innerWidth <= 768 ? 3 : 5;
        cardWidth = viewport.clientWidth / VISIBLE_COUNT;
        cards.forEach(card => { card.style.width = cardWidth + 'px'; });
        trackPosition = setLength * cardWidth;
    }

    function easeSmooth(t) { return t * t * (3 - 2 * t); }

    function tick(time) {
        if (lastTime === null) lastTime = time;
        const dt = (time - lastTime) / 1000;
        lastTime = time;

        if (!paused && cardWidth > 0) {
            const phase = (trackPosition / cardWidth) % 1;
            const speedFactor = 1 - SPEED_VARIANCE * Math.cos(2 * Math.PI * phase);
            trackPosition += BASE_SPEED * speedFactor * dt;

            const loopLength = setLength * cardWidth;
            if (trackPosition >= loopLength * 2) trackPosition -= loopLength;
        }

        track.style.transform = `translateX(${-trackPosition}px)`;

        const viewportCenter = viewport.clientWidth / 2;
        cards.forEach((card, i) => {
            const cardCenter = i * cardWidth + cardWidth / 2 - trackPosition;
            const t = Math.min(Math.abs(cardCenter - viewportCenter) / cardWidth, 1);
            const scale = MAX_SCALE - (MAX_SCALE - MIN_SCALE) * easeSmooth(t);
            card.style.transform = `scale(${scale})`;
            card.style.zIndex = String(Math.round((1 - t) * 100));
        });

        requestAnimationFrame(tick);
    }

    measure();
    window.addEventListener('resize', measure);
    viewport.addEventListener('mouseenter', () => { paused = true; });
    viewport.addEventListener('mouseleave', () => { paused = false; });
    requestAnimationFrame(tick);
    })();
    </script>
    </body>
    </html>