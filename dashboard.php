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

    // Which individual positions has this student already voted on, across
    // any election? (votes has UNIQUE(user_id, position_id), so one row per
    // position is all there ever is.) Used by the dashboard home to show a
    // real per-position checklist instead of just one all-or-nothing badge.
    $votedPositionIds = [];
    $vpStmt = $pdo->prepare('SELECT position_id FROM votes WHERE user_id = :uid');
    $vpStmt->execute(['uid' => $user['id']]);
    $votedPositionIds = array_map('intval', $vpStmt->fetchAll(PDO::FETCH_COLUMN));

    // Saved-for-later ballot drafts, keyed by election_id. Never counted as
    // actual votes — used only to pre-fill an in-progress ballot and to show
    // "Position X of Y voted" progress on the dashboard before submission.
    $draftsByElection = [];
    $draftStmt = $pdo->prepare('SELECT election_id, selections FROM vote_drafts WHERE user_id = :uid');
    $draftStmt->execute(['uid' => $user['id']]);
    foreach ($draftStmt->fetchAll() as $draftRow) {
        $decoded = json_decode($draftRow['selections'], true);
        $draftsByElection[(int) $draftRow['election_id']] = is_array($decoded) ? $decoded : [];
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

    /**
     * Turns a countdown into something a human wants to read. A raw
     * HH:MM:SS clock looks fine for "3 hours left" but turns into an
     * illegible "486:04:11" once an election runs for multiple weeks — so
     * this steps down to whichever unit is actually meaningful:
     *   >= 1 day   -> "20d 6h left"
     *   >= 1 hour  -> "4h 32m left"
     *   otherwise  -> "8m 15s left" (fine-grained since it's closing soon)
     */
    function formatCountdown(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Closing now';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return "{$days}d {$hours}h left";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m left";
        }
        return "{$minutes}m {$secs}s left";
    }

    /**
     * One continuous progress line with a checkpoint for EVERY position in
     * this specific election, not just a fixed 3-point summary:
     *   Start -- Position 1 -- Position 2 -- ... -- Ballot Complete -- Submit
     * Built dynamically from $positions, so an election with 2 positions
     * and one with 10 get correspondingly different stepper lengths.
     *
     * A segment fills only once the node to its LEFT is filled, so the
     * green fill reads as contiguous progress from the start rather than
     * jumping around if positions were answered out of order. The final
     * segment (Ballot Complete -> Submit) is strictly 0% or 100% — it only
     * fills once the ballot is ACTUALLY submitted, so a fully-answered-
     * but-unsubmitted draft still visibly stops short of the end.
     *
     * $positions: this election's position rows (each needs 'position_id' and 'title')
     * $answeredPositionIds: position IDs that already have an answer (draft or real vote)
     * $submitted: whether the ballot has actually been cast
     */
    function renderBallotProgressStepper(array $positions, array $answeredPositionIds, bool $submitted): string
    {
        $total = count($positions);
        $answeredCount = 0;
        foreach ($positions as $pos) {
            if (in_array((int) $pos['position_id'], $answeredPositionIds, true)) {
                $answeredCount++;
            }
        }
        $allAnswered = $total > 0 && $answeredCount === $total;

        $nodes = '<div class="bps-dot filled" title="Started"></div>';
        $prevFilled = true; // "Start" is always considered reached

        foreach ($positions as $i => $pos) {
            $isAnswered = in_array((int) $pos['position_id'], $answeredPositionIds, true);
            $segPct = $prevFilled ? 100 : 0;
            $nodes .= '<div class="bps-line"><div class="bps-line-fill" style="width: ' . $segPct . '%;"></div></div>';
            $nodes .= '<div class="bps-dot' . ($isAnswered ? ' filled' : '') . '" title="' . h($pos['title']) . '"><span class="bps-dot-num">' . ($i + 1) . '</span></div>';
            $prevFilled = $isAnswered;
        }

        $segPct = $prevFilled ? 100 : 0;
        $nodes .= '<div class="bps-line"><div class="bps-line-fill" style="width: ' . $segPct . '%;"></div></div>';
        $nodes .= '<div class="bps-dot' . ($allAnswered ? ' filled' : '') . '" title="Ballot Complete"></div>';

        $segPct = $submitted ? 100 : 0;
        $nodes .= '<div class="bps-line"><div class="bps-line-fill" style="width: ' . $segPct . '%;"></div></div>';
        $nodes .= '<div class="bps-dot' . ($submitted ? ' filled' : '') . '" title="Submitted"></div>';

        return '
        <div class="ballot-progress-stepper">' . $nodes . '</div>
        <div class="bps-labels">
            <span>Start</span>
            <span>' . $answeredCount . ' of ' . $total . ' positions</span>
            <span>Submit</span>
        </div>';
    }

    /**
     * The status key used both for the card's top-border color and for
     * grouping/sorting the election list — Ongoing first, then Paused,
     * then Upcoming, then Ended, instead of raw chronological order (which
     * interleaves ongoing/paused/ended elections in a confusing mix).
     */
    function electionStatusKey(bool $isOpen, string $statusClass): string
    {
        return $isOpen ? 'ongoing' : ($statusClass ?: 'closed');
    }

    /**
     * Returns election IDs from $electionData ordered Ongoing -> Paused ->
     * Upcoming -> Ended, preserving each group's original (chronological)
     * relative order. Display-only — doesn't touch $electionData itself.
     */
    function sortedElectionIds(array $electionData, DateTime $now): array
    {
        $priority = ['ongoing' => 0, 'paused' => 1, 'scheduled' => 2, 'closed' => 3];
        $withKey = [];
        foreach ($electionData as $eid => $data) {
            [$isOpen, , $statusClass] = electionUiStatus($data['config'], $now);
            $withKey[] = ['eid' => $eid, 'rank' => $priority[electionStatusKey($isOpen, $statusClass)] ?? 99];
        }
        usort($withKey, fn($a, $b) => $a['rank'] <=> $b['rank']);
        return array_column($withKey, 'eid');
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
    $ssgLogo = getSiteSetting($pdo, 'ssg_logo');
    $departmentLogos = getDepartmentLogos($pdo);
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
                'icon' => electionLogoHtml($e['type'], $e['department'], $ssgLogo, $departmentLogos),
                'subtitle' => $e['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government' . ($e['department'] ? ' — ' . $e['department'] : ''),
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

    // ====================================================================
    // BRANCH A — the actual ballot for one specific election. Deliberately
    // kept as its own full server-rendered page (not part of the SPA
    // below): this is the single highest-stakes code path in the app, it
    // already has its own smooth in-page step animations, and clicking
    // into it is a deliberate action rather than casual browsing.
    // Everything else (Home / Vote-picker / Candidates / Results) below
    // was converted to client-rendered panels specifically so switching
    // between THOSE no longer does a full page reload — this branch
    // intentionally still does.
    // ====================================================================
    if ($page === 'vote' && $selectedElectionId):
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Vote | School Elections</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/dashboard-app.css">
    <script>
    (function () {
        var saved = localStorage.getItem('studentTheme');
        var theme = saved === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    </head>
    <body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="sidebar" id="sidebar">
        <div class="logo-area">
            <img src="assets/logo.png" alt="FICT Logo">
            <h2>School Elections</h2>
            <span class="logo-tagline">Student Portal</span>
        </div>
        <nav>
            <a href="dashboard.php?page=home"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5Z"/></svg></span><span>Dashboard</span></a>
            <a href="dashboard.php?page=vote" class="active"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M7.5 12l3 3 6-6.5"/></svg></span><span>Vote</span></a>
            <a href="dashboard.php?page=candidates"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Candidates</span></a>
            <a href="dashboard.php?page=results"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span><span>Results</span></a>
        </nav>
        <div class="theme-switcher">
            <span class="ts-label" id="themeLabel"><span class="nav-icon" id="themeIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></span> Light Mode</span>
            <button type="button" class="theme-toggle" id="themeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode"></button>
        </div>
        <div class="sidebar-bottom">
            <div class="profile-section">
                <div class="avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                <div class="user-info">
                    <div class="name"><?= h($user['name']) ?></div>
                    <div class="role"><?= h($user['department'] ?: 'Student') ?><?= $user['year_level'] ? ' · ' . h($user['year_level']) : '' ?></div>
                </div>
            </div>
            <div class="logout-link">
                <a href="logout.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span><span>Log Out</span></a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">☰</button>
                <h1>Welcome, <?= h($user['name']) ?></h1>
            </div>
            <span class="date"><?= date('l, F j, Y') ?></span>
        </div>

        <?php
            $now = new DateTime();
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
                                <div class="ballot-header-actions">
                                    <button type="button" class="ballot-save-exit" id="ballotSaveExitBtn" onclick="saveDraftAndExit()">💾 Save &amp; Exit</button>
                                    <a href="dashboard.php?page=vote" class="ballot-exit" onclick="return handleBallotExitClick(event);">Exit</a>
                                </div>
                            </div>
                            <div class="ballot-progress-track">
                                <div class="ballot-progress-fill" id="ballotProgressFill"></div>
                            </div>
                        </div>

                        <div id="statusMessage" class="alert" style="display:none;"></div>

                        <form id="voteForm" data-election="<?= $selectedElectionId ?>" data-total-positions="<?= $totalPositions ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="election_id" value="<?= $selectedElectionId ?>">

                            <?php $currentDraft = $draftsByElection[$selectedElectionId] ?? []; ?>
                            <?php foreach ($positions as $index => $position): ?>
                                <?php
                                    $candidates = $candidatesByPosition[$position['position_id']] ?? [];
                                    $winnerCount = max(1, (int) ($position['winner_count'] ?? 1));
                                    $isMulti = $winnerCount > 1;
                                    $inputType = $isMulti ? 'checkbox' : 'radio';
                                    $inputName = 'position_' . (int) $position['position_id'] . ($isMulti ? '[]' : '');
                                    $draftPicks = array_map('intval', $currentDraft[(string) $position['position_id']] ?? []);
                                ?>
                                <div class="ballot-step<?= $index === 0 ? ' active' : '' ?>" data-step="<?= $index ?>" data-kind="position" data-position-title="<?= h($position['title']) ?>" data-max-select="<?= $winnerCount ?>">
                                    <div class="ballot-step-heading">
                                        <span class="ballot-step-eyebrow">Position <?= $index + 1 ?> of <?= $totalPositions ?></span>
                                        <h3 class="ballot-position-title"><?= h($position['title']) ?></h3>
                                        <?php if (!empty($position['year_restriction'])): ?>
                                            <p class="position-note">Only visible to <?= h($position['year_restriction']) ?> students.</p>
                                        <?php endif; ?>
                                        <p class="ballot-instruction">
                                            <?php if ($isMulti): ?>
                                                Select up to <?= $winnerCount ?> candidates, then continue.
                                                <span class="ballot-select-count" data-count-for="<?= $index ?>">0 of <?= $winnerCount ?> selected</span>
                                            <?php else: ?>
                                                Select one candidate, then continue.
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <?php if (empty($candidates)): ?>
                                        <p class="muted">No candidates registered for this position.</p>
                                    <?php else: ?>
                                        <fieldset class="ballot-candidates" data-max-select="<?= $winnerCount ?>">
                                            <?php foreach ($candidates as $candidate):
                                                $partyLabel = (!empty($candidate['party']) && $candidate['party'] !== 'No Party / Independent') ? $candidate['party'] : 'Independent';
                                                $isPreChecked = in_array((int) $candidate['id'], $draftPicks, true);
                                            ?>
                                                <label class="ballot-candidate">
                                                    <input type="<?= $inputType ?>" class="ballot-radio-input" name="<?= $inputName ?>" value="<?= (int) $candidate['id'] ?>" <?= $isPreChecked ? 'checked' : '' ?> data-candidate-name="<?= h($candidate['name']) ?>" data-candidate-photo="<?= h(!empty($candidate['photo']) ? $candidate['photo'] : 'assets/default-avatar.png') ?>">
                                                    <span class="ballot-bubble<?= $isMulti ? ' multi' : '' ?>" aria-hidden="true"><span class="ballot-bubble-fill"></span></span>
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
                                                    <img class="candidate-avatar" src="<?= h(!empty($candidate['photo']) ? $candidate['photo'] : 'assets/default-avatar.png') ?>" alt="">
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

                                <div class="ballot-warning">
                                    <span>⚠️</span>
                                    <span>Once submitted, your vote cannot be changed. Please review your selections carefully before continuing.</span>
                                </div>

                                <div class="ballot-nav">
                                    <button type="button" class="btn btn-secondary" onclick="ballotPrev()">&larr; Back</button>
                                    <button type="button" class="btn btn-primary" id="submitBtn" onclick="openBallotConfirm()">Submit My Vote</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="ballot-modal-overlay" id="ballotConfirmModal">
                        <div class="ballot-modal-card">
                            <h2>Submit your vote?</h2>
                            <p>Your selections cannot be changed after submission.</p>
                            <div class="ballot-nav" style="margin-top:0;">
                                <button type="button" class="btn btn-secondary" onclick="closeBallotConfirm()">Cancel</button>
                                <button type="button" class="btn btn-primary" id="confirmSubmitBtn" onclick="confirmBallotSubmit()">Confirm Vote</button>
                            </div>
                        </div>
                    </div>

                    <div class="ballot-modal-overlay" id="ballotSuccessModal">
                        <div class="ballot-modal-card">
                            <div class="ballot-modal-check">✔</div>
                            <h2>Vote Submitted!</h2>
                            <p id="ballotModalMessage">Thank you for participating in the election. Your voice has been counted.</p>
                            <div class="ballot-nav" style="margin-top:0;">
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php?page=results';">View Results</button>
                                <button type="button" class="btn btn-primary" onclick="closeBallotModal()">Return to Dashboard</button>
                            </div>
                        </div>
                    </div>
        <?php
                }
            }
        ?>
    </div>

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

    const THEME_ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>';
    const THEME_ICON_SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const themeLabel = document.getElementById('themeLabel');
    function applyThemeUI(theme) {
        const isDark = theme === 'dark';
        if (themeToggleBtn) themeToggleBtn.setAttribute('aria-checked', String(isDark));
        if (themeIcon) themeIcon.innerHTML = isDark ? THEME_ICON_MOON : THEME_ICON_SUN;
        if (themeLabel && themeLabel.childNodes[1]) themeLabel.childNodes[1].textContent = isDark ? ' Dark Mode' : ' Light Mode';
    }
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('studentTheme', theme); } catch (e) {}
        applyThemeUI(theme);
    }
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
    applyThemeUI(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    const toastStack = document.createElement('div');
    toastStack.className = 'toast-stack';
    document.body.appendChild(toastStack);
    const TOAST_ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="16" x2="12" y2="11"/><circle cx="12" cy="7.5" r=".5" fill="currentColor" stroke="none"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><circle cx="12" cy="16.5" r=".5" fill="currentColor" stroke="none"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>',
    };
    function showToast(message, type, opts) {
        type = type || 'info'; opts = opts || {};
        const duration = opts.duration !== undefined ? opts.duration : 4500;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        const msgSpan = document.createElement('span');
        msgSpan.className = 'toast-icon';
        msgSpan.innerHTML = TOAST_ICONS[type] || TOAST_ICONS.info;
        const textSpan = document.createElement('span');
        textSpan.className = 'toast-msg';
        textSpan.textContent = message;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button'; closeBtn.className = 'toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss'); closeBtn.textContent = '\u2715';
        el.appendChild(msgSpan); el.appendChild(textSpan); el.appendChild(closeBtn);
        const remove = () => { el.classList.add('leaving'); setTimeout(() => el.remove(), 200); };
        closeBtn.addEventListener('click', remove);
        toastStack.appendChild(el);
        if (duration) setTimeout(remove, duration);
        return el;
    }
    function showConfirmModal(opts) {
        opts = opts || {};
        const title = opts.title || 'Are you sure?';
        const message = opts.message || '';
        const confirmLabel = opts.confirmLabel || 'Confirm';
        const cancelLabel = opts.cancelLabel || 'Cancel';
        const danger = opts.danger !== undefined ? opts.danger : true;
        return new Promise(resolve => {
            const overlay2 = document.createElement('div');
            overlay2.className = 'modal-overlay';
            overlay2.innerHTML = `
                <div class="modal-box" role="alertdialog" aria-modal="true">
                    <div class="modal-icon ${danger ? 'danger' : 'neutral'}">
                        ${danger
                            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'}
                    </div>
                    <h3></h3>
                    <p></p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-choice="cancel"></button>
                        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-choice="confirm"></button>
                    </div>
                </div>
            `;
            overlay2.querySelector('h3').textContent = title;
            overlay2.querySelector('p').textContent = message;
            overlay2.querySelector('[data-choice="cancel"]').textContent = cancelLabel;
            overlay2.querySelector('[data-choice="confirm"]').textContent = confirmLabel;
            function finish(result) { overlay2.remove(); document.removeEventListener('keydown', onKey); resolve(result); }
            function onKey(e) { if (e.key === 'Escape') finish(false); }
            overlay2.addEventListener('click', e => { if (e.target === overlay2) finish(false); });
            overlay2.querySelector('[data-choice="cancel"]').addEventListener('click', () => finish(false));
            overlay2.querySelector('[data-choice="confirm"]').addEventListener('click', () => finish(true));
            document.addEventListener('keydown', onKey);
            document.body.appendChild(overlay2);
            overlay2.querySelector('[data-choice="confirm"]').focus();
        });
    }

    (function () {
        const voteForm = document.getElementById('voteForm');
        if (!voteForm) return;

        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str ?? '';
            return d.innerHTML;
        }

        const steps = Array.from(voteForm.querySelectorAll('.ballot-step'));
        let currentStep = 0;

        const progressFill = document.getElementById('ballotProgressFill');
        const summaryList = document.getElementById('ballotSummaryList');
        const statusMessage = document.getElementById('statusMessage');
        const submitBtn = document.getElementById('submitBtn');
        const modal = document.getElementById('ballotSuccessModal');

        function updateSelectCount(step, fieldset) {
            const countEl = step.querySelector('.ballot-select-count');
            if (!countEl) return;
            const max = parseInt(fieldset.dataset.maxSelect || '1', 10);
            const checked = fieldset.querySelectorAll('.ballot-radio-input:checked').length;
            countEl.textContent = `${checked} of ${max} selected`;
        }

        function updateDimming(fieldset, step) {
            if (!fieldset) return;
            const labels = Array.from(fieldset.querySelectorAll('.ballot-candidate'));
            const inputs = labels.map(l => l.querySelector('.ballot-radio-input'));
            const isMulti = inputs.length > 0 && inputs[0].type === 'checkbox';
            const maxSelect = parseInt(fieldset.dataset.maxSelect || '1', 10);
            const checkedCount = inputs.filter(i => i.checked).length;
            labels.forEach((label, i) => {
                const input = inputs[i];
                label.classList.toggle('selected', input.checked);
                if (isMulti) {
                    const atLimit = checkedCount >= maxSelect;
                    label.classList.toggle('dimmed', atLimit && !input.checked);
                    input.disabled = atLimit && !input.checked;
                } else {
                    label.classList.toggle('dimmed', checkedCount > 0 && !input.checked);
                }
            });
            if (step) updateSelectCount(step, fieldset);
        }

        steps.forEach(step => {
            const fieldset = step.querySelector('.ballot-candidates');
            if (!fieldset) return;
            fieldset.addEventListener('change', () => updateDimming(fieldset, step));
            updateDimming(fieldset, step);
        });

        function updateProgress() {
            const pct = ((currentStep + 1) / steps.length) * 100;
            if (progressFill) progressFill.style.width = pct + '%';
        }

        function buildSummary() {
            if (!summaryList) return;
            const positionSteps = steps.filter(s => s.dataset.kind === 'position');
            summaryList.innerHTML = positionSteps.map(step => {
                const title = step.dataset.positionTitle || '';
                const stepIndex = steps.indexOf(step);
                const checkedInputs = Array.from(step.querySelectorAll('.ballot-radio-input:checked'));
                if (checkedInputs.length === 0) {
                    return `
                    <div class="ballot-summary-block">
                        <div class="ballot-summary-block-header">
                            <span class="ballot-summary-position">${escHtml(title)}</span>
                            ${step.querySelector('.ballot-candidates') ? `<button type="button" class="ballot-summary-change" onclick="ballotGoTo(${stepIndex})">Select</button>` : ''}
                        </div>
                        <div class="ballot-summary-candidates"><div class="ballot-summary-name none">No selection</div></div>
                    </div>`;
                }
                const rows = checkedInputs.map(input => {
                    const name = input.dataset.candidateName || '';
                    const photo = input.dataset.candidatePhoto || 'assets/default-avatar.png';
                    const partyEl = input.closest('.ballot-candidate').querySelector('.ballot-candidate-party');
                    const party = partyEl ? partyEl.textContent : '';
                    return `
                    <div class="ballot-summary-candidate-row">
                        <img class="ballot-summary-photo" src="${escHtml(photo)}" alt="">
                        <div class="ballot-summary-text">
                            <div class="ballot-summary-name">${escHtml(name)}</div>
                            <div class="ballot-summary-party">${escHtml(party)}</div>
                        </div>
                    </div>`;
                }).join('');
                return `
                <div class="ballot-summary-block">
                    <div class="ballot-summary-block-header">
                        <span class="ballot-summary-position">${escHtml(title)}</span>
                        <button type="button" class="ballot-summary-change" onclick="ballotGoTo(${stepIndex})">Change</button>
                    </div>
                    <div class="ballot-summary-candidates">${rows}</div>
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
            if (!fieldset) return true;
            return !!fieldset.querySelector('.ballot-radio-input:checked');
        }

        window.ballotNext = function () {
            if (!currentStepValid()) { showToast('Please select a candidate before continuing.', 'warning'); return; }
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
        window.handleBallotExitClick = function (e) {
            const anySelected = voteForm.querySelector('.ballot-radio-input:checked');
            if (!anySelected) return true;
            e.preventDefault();
            showConfirmModal({
                title: 'Leave without submitting?',
                message: 'You have selections on this ballot that haven\'t been submitted. Tip: use "Save & Exit" instead to keep them.',
                confirmLabel: 'Leave Anyway',
                cancelLabel: 'Stay',
                danger: true,
            }).then(confirmed => { if (confirmed) window.location.href = 'dashboard.php?page=vote'; });
            return false;
        };

        function gatherSelections() {
            const selections = {};
            voteForm.querySelectorAll('.ballot-candidates').forEach(fieldset => {
                const checked = Array.from(fieldset.querySelectorAll('.ballot-radio-input:checked'));
                if (checked.length === 0) return;
                const match = checked[0].name.match(/^position_(\d+)/);
                if (!match) return;
                selections[match[1]] = checked.map(i => parseInt(i.value, 10));
            });
            return selections;
        }

        window.saveDraftAndExit = async function () {
            const btn = document.getElementById('ballotSaveExitBtn');
            const btnOriginalHTML = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="btn-spinner dark"></span> Saving...'; }
            if (statusMessage) statusMessage.style.display = 'none';
            const csrfInput = voteForm.querySelector('input[name="csrf_token"]');
            try {
                const res = await fetch('draft.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: csrfInput ? csrfInput.value : '',
                        election_id: voteForm.dataset.election,
                        selections: gatherSelections(),
                    }),
                });
                const data = await res.json();
                if (data.success) { window.location.href = 'dashboard.php?page=home'; return; }
                if (btn) { btn.disabled = false; btn.innerHTML = btnOriginalHTML; }
                showToast(data.message || 'Could not save your progress. Please try again.', 'error');
            } catch (err) {
                if (btn) { btn.disabled = false; btn.innerHTML = btnOriginalHTML; }
                showToast('Network error. Could not save your progress.', 'error');
            }
        };

        window.closeBallotModal = function () { window.location.href = 'dashboard.php?page=home'; };

        const confirmModal = document.getElementById('ballotConfirmModal');
        window.openBallotConfirm = function () { if (confirmModal) confirmModal.classList.add('active'); };
        window.closeBallotConfirm = function () { if (confirmModal) confirmModal.classList.remove('active'); };

        async function performBallotSubmit() {
            if (!submitBtn) return;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-spinner"></span> Submitting...';
            const confirmBtn = document.getElementById('confirmSubmitBtn');
            if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.innerHTML = '<span class="btn-spinner"></span> Submitting...'; }
            if (statusMessage) statusMessage.style.display = 'none';
            const formData = new FormData(voteForm);
            try {
                const response = await fetch('vote.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    voteForm.querySelectorAll('input, button').forEach(el => el.disabled = true);
                    if (confirmModal) confirmModal.classList.remove('active');
                    if (modal) {
                        const msgEl = document.getElementById('ballotModalMessage');
                        if (msgEl && data.message) msgEl.textContent = data.message;
                        modal.classList.add('active');
                    }
                } else {
                    if (confirmModal) confirmModal.classList.remove('active');
                    showToast(data.message || 'Something went wrong. Please try again.', 'error');
                    submitBtn.disabled = false; submitBtn.textContent = 'Submit My Vote';
                    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm Vote'; }
                }
            } catch (err) {
                if (confirmModal) confirmModal.classList.remove('active');
                showToast('Network error. Please try again.', 'error');
                submitBtn.disabled = false; submitBtn.textContent = 'Submit My Vote';
                if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Confirm Vote'; }
            }
        }
        window.confirmBallotSubmit = function () { performBallotSubmit(); };

        const firstIncompleteIndex = steps.findIndex(step => {
            if (step.dataset.kind !== 'position') return false;
            const fieldset = step.querySelector('.ballot-candidates');
            if (!fieldset) return false;
            return !fieldset.querySelector('.ballot-radio-input:checked');
        });
        showStep(firstIncompleteIndex !== -1 ? firstIncompleteIndex : steps.length - 1);

        voteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (steps[currentStep] && steps[currentStep].dataset.kind === 'review') window.openBallotConfirm();
        });
    })();
    </script>
    </body>
    </html>
    <?php
    exit;
    endif;
    // ====================================================================
    // BRANCH B — the SPA shell. Loaded once; Home / Vote-picker /
    // Candidates / Results are all client-rendered panels from here on,
    // fetching data from dashboard-data.php instead of doing a full page
    // reload per section.
    // ====================================================================
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard | School Elections</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/dashboard-app.css">
    <script>
    // Applied synchronously, before the body renders, so the page never
    // flashes light-then-dark on load. Default is light unless the
    // student explicitly flipped the switch before (saved in
    // localStorage under its own key, separate from the admin panel's).
    (function () {
        var saved = localStorage.getItem('studentTheme');
        var theme = saved === 'dark' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    </head>
    <body>
    <!-- Sidebar overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo-area">
            <img src="assets/logo.png" alt="FICT Logo">
            <h2>School Elections</h2>
            <span class="logo-tagline">Student Portal</span>
        </div>
        <nav>
            <a data-panel="home" class="nav-link active" onclick="showPanel('home')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1V9.5Z"/></svg></span><span>Dashboard</span></a>
            <a data-panel="vote" class="nav-link" onclick="showPanel('vote')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M7.5 12l3 3 6-6.5"/></svg></span><span>Vote</span></a>
            <a data-panel="candidates" class="nav-link" onclick="showPanel('candidates')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Candidates</span></a>
            <a data-panel="results" class="nav-link" onclick="showPanel('results')"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span><span>Results</span></a>
        </nav>
        <div class="theme-switcher">
            <span class="ts-label" id="themeLabel"><span class="nav-icon" id="themeIcon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg></span> Light Mode</span>
            <button type="button" class="theme-toggle" id="themeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode"></button>
        </div>
        <div class="sidebar-bottom">
            <div class="profile-section">
                <div class="avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                <div class="user-info">
                    <div class="name"><?= h($user['name']) ?></div>
                    <div class="role"><?= h($user['department'] ?: 'Student') ?><?= $user['year_level'] ? ' · ' . h($user['year_level']) : '' ?></div>
                </div>
            </div>
            <div class="logout-link">
                <a href="logout.php"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span><span>Log Out</span></a>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">☰</button>
                <h1 id="pageHeaderTitle">Welcome, <?= h($user['name']) ?></h1>
            </div>
            <span class="date"><?= date('l, F j, Y') ?></span>
        </div>

        <div class="panel active" id="panel-home"></div>
        <div class="panel" id="panel-vote"></div>
        <div class="panel" id="panel-candidates"></div>
        <div class="panel" id="panel-results"></div>
    </div>

    <script>
    <?php echo "const CSRF_TOKEN = " . json_encode($csrfToken) . ";\n"; ?>
    const STUDENT_NAME = <?= json_encode($user['name']) ?>;
    const DASHBOARD_DATA_API = 'dashboard-data.php';

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

    // ------------------------------------------------------------------
    // THEME SWITCHER (dark / light)
    // ------------------------------------------------------------------
    const THEME_ICON_MOON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>';
    const THEME_ICON_SUN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const themeIcon = document.getElementById('themeIcon');
    const themeLabel = document.getElementById('themeLabel');

    function applyThemeUI(theme) {
        const isDark = theme === 'dark';
        if (themeToggleBtn) themeToggleBtn.setAttribute('aria-checked', String(isDark));
        if (themeIcon) themeIcon.innerHTML = isDark ? THEME_ICON_MOON : THEME_ICON_SUN;
        if (themeLabel && themeLabel.childNodes[1]) themeLabel.childNodes[1].textContent = isDark ? ' Dark Mode' : ' Light Mode';
    }
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('studentTheme', theme); } catch (e) {}
        applyThemeUI(theme);
    }
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        });
    }
    applyThemeUI(document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    // ------------------------------------------------------------------
    // TOASTS + CONFIRM MODAL (same pattern as the admin panel)
    // ------------------------------------------------------------------
    const toastStack = document.createElement('div');
    toastStack.className = 'toast-stack';
    document.body.appendChild(toastStack);

    const TOAST_ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="16" x2="12" y2="11"/><circle cx="12" cy="7.5" r=".5" fill="currentColor" stroke="none"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><circle cx="12" cy="16.5" r=".5" fill="currentColor" stroke="none"/><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>',
    };

    function showToast(message, type, opts) {
        type = type || 'info';
        opts = opts || {};
        const duration = opts.duration !== undefined ? opts.duration : 4500;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        const msgSpan = document.createElement('span');
        msgSpan.className = 'toast-icon';
        msgSpan.innerHTML = TOAST_ICONS[type] || TOAST_ICONS.info;
        const textSpan = document.createElement('span');
        textSpan.className = 'toast-msg';
        textSpan.textContent = message;
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'toast-close';
        closeBtn.setAttribute('aria-label', 'Dismiss');
        closeBtn.textContent = '\u2715';
        el.appendChild(msgSpan);
        el.appendChild(textSpan);
        el.appendChild(closeBtn);
        const remove = () => { el.classList.add('leaving'); setTimeout(() => el.remove(), 200); };
        closeBtn.addEventListener('click', remove);
        toastStack.appendChild(el);
        if (duration) setTimeout(remove, duration);
        return el;
    }

    function showConfirmModal(opts) {
        opts = opts || {};
        const title = opts.title || 'Are you sure?';
        const message = opts.message || '';
        const confirmLabel = opts.confirmLabel || 'Confirm';
        const cancelLabel = opts.cancelLabel || 'Cancel';
        const danger = opts.danger !== undefined ? opts.danger : true;
        return new Promise(resolve => {
            const overlay2 = document.createElement('div');
            overlay2.className = 'modal-overlay';
            overlay2.innerHTML = `
                <div class="modal-box" role="alertdialog" aria-modal="true">
                    <div class="modal-icon ${danger ? 'danger' : 'neutral'}">
                        ${danger
                            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'}
                    </div>
                    <h3></h3>
                    <p></p>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-choice="cancel"></button>
                        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-choice="confirm"></button>
                    </div>
                </div>
            `;
            overlay2.querySelector('h3').textContent = title;
            overlay2.querySelector('p').textContent = message;
            overlay2.querySelector('[data-choice="cancel"]').textContent = cancelLabel;
            overlay2.querySelector('[data-choice="confirm"]').textContent = confirmLabel;
            function finish(result) {
                overlay2.remove();
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onKey(e) { if (e.key === 'Escape') finish(false); }
            overlay2.addEventListener('click', e => { if (e.target === overlay2) finish(false); });
            overlay2.querySelector('[data-choice="cancel"]').addEventListener('click', () => finish(false));
            overlay2.querySelector('[data-choice="confirm"]').addEventListener('click', () => finish(true));
            document.addEventListener('keydown', onKey);
            document.body.appendChild(overlay2);
            overlay2.querySelector('[data-choice="confirm"]').focus();
        });
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    // ------------------------------------------------------------------
    // JS PORTS of the PHP helper functions that used to run server-side
    // per page load — now run client-side against the one JSON payload
    // fetched from dashboard-data.php, so switching panels never needs a
    // full page reload.
    // ------------------------------------------------------------------
    function formatCountdown(seconds) {
        if (seconds <= 0) return 'Closing now';
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = Math.floor(seconds % 60);
        if (days > 0) return `${days}d ${hours}h left`;
        if (hours > 0) return `${hours}h ${minutes}m left`;
        return `${minutes}m ${secs}s left`;
    }

    // Mirrors electionUiStatus() in PHP: turns raw status + schedule into
    // {isOpen, statusText, statusClass, start, end}.
    function electionUiStatus(config, now) {
        const start = new Date(config.start_date.replace(' ', 'T'));
        const end = new Date(config.end_date.replace(' ', 'T'));
        const isOpen = config.status === 'ongoing' && now >= start && now <= end;
        let statusText, statusClass;
        if (isOpen) { statusText = 'Ongoing'; statusClass = ''; }
        else if (config.status === 'paused') { statusText = 'Paused'; statusClass = 'paused'; }
        else if (['draft', 'scheduled'].includes(config.status) || now < start) { statusText = 'Not started'; statusClass = 'scheduled'; }
        else { statusText = 'Ended'; statusClass = 'closed'; }
        return { isOpen, statusText, statusClass, start, end };
    }

    function electionStatusKey(isOpen, statusClass) {
        return isOpen ? 'ongoing' : (statusClass || 'closed');
    }

    // Mirrors sortedElectionIds()/groups building in PHP: returns an
    // ordered array of {key, elections} groups — Ongoing -> Paused ->
    // Upcoming -> Ended — each preserving the original chronological order
    // within the group.
    function groupElections(elections, now) {
        const order = ['ongoing', 'paused', 'scheduled', 'closed'];
        const buckets = { ongoing: [], paused: [], scheduled: [], closed: [] };
        elections.forEach(e => {
            const { isOpen, statusClass } = electionUiStatus(e.config, now);
            const key = electionStatusKey(isOpen, statusClass);
            (buckets[key] || buckets.closed).push(e);
        });
        return order.filter(k => buckets[k].length).map(k => ({ key: k, elections: buckets[k] }));
    }

    const GROUP_LABELS = { ongoing: 'Ongoing', paused: 'Paused', scheduled: 'Upcoming', closed: 'Ended' };
    const GROUP_DOT_COLOR = { ongoing: '#22c55e', paused: '#f59e0b', scheduled: '#6366f1', closed: '#ef4444' };

    // Mirrors renderBallotProgressStepper() in PHP.
    function renderBallotProgressStepper(positions, answeredPositionIds, submitted) {
        const total = positions.length;
        let answeredCount = 0;
        positions.forEach(p => { if (answeredPositionIds.includes(p.position_id)) answeredCount++; });
        const allAnswered = total > 0 && answeredCount === total;

        let nodes = '<div class="bps-dot filled" title="Started"></div>';
        let prevFilled = true;
        positions.forEach((pos, i) => {
            const isAnswered = answeredPositionIds.includes(pos.position_id);
            const segPct = prevFilled ? 100 : 0;
            nodes += `<div class="bps-line"><div class="bps-line-fill" style="width: ${segPct}%;"></div></div>`;
            nodes += `<div class="bps-dot${isAnswered ? ' filled' : ''}" title="${escHtml(pos.title)}"><span class="bps-dot-num">${i + 1}</span></div>`;
            prevFilled = isAnswered;
        });
        let segPct = prevFilled ? 100 : 0;
        nodes += `<div class="bps-line"><div class="bps-line-fill" style="width: ${segPct}%;"></div></div>`;
        nodes += `<div class="bps-dot${allAnswered ? ' filled' : ''}" title="Ballot Complete"></div>`;
        segPct = submitted ? 100 : 0;
        nodes += `<div class="bps-line"><div class="bps-line-fill" style="width: ${segPct}%;"></div></div>`;
        nodes += `<div class="bps-dot${submitted ? ' filled' : ''}" title="Submitted"></div>`;

        return `
        <div class="ballot-progress-stepper">${nodes}</div>
        <div class="bps-labels">
            <span>Start</span>
            <span>${answeredCount} of ${total} positions</span>
            <span>Submit</span>
        </div>`;
    }

    function answeredPositionIdsFor(election) {
        // Server already merges real votes + saved drafts into this list
        // per election (see dashboard-data.php) — no need to redo that
        // merge here.
        return election.answeredPositionIds || [];
    }

    // ------------------------------------------------------------------
    // SKELETON LOADERS — shown immediately on panel switch, before the
    // (usually near-instant, since it's one cached fetch) data is ready.
    // ------------------------------------------------------------------
    function skeletonHome() {
        return `
            <div class="skeleton skel-card" style="height:220px;margin-bottom:1.75rem;"></div>
            <div class="quick-actions">${'<div class="skeleton skel-card" style="height:96px;"></div>'.repeat(3)}</div>
            <div class="skeleton skel-line w40" style="height:22px;margin:1.5rem 0 1rem;"></div>
            <div class="skeleton skel-card" style="height:200px;margin-bottom:1.5rem;"></div>
            <div class="skeleton skel-card" style="height:200px;"></div>
        `;
    }
    function skeletonCards() {
        return `
            <div class="skeleton skel-line w40" style="height:22px;margin-bottom:1rem;"></div>
            <div class="skeleton skel-card" style="height:220px;margin-bottom:1.5rem;"></div>
            <div class="skeleton skel-card" style="height:220px;"></div>
        `;
    }
    function skeletonCandidates() {
        return `
            <div class="skeleton skel-line w40" style="height:28px;margin-bottom:1.5rem;"></div>
            <div class="cand-grid">${'<div class="skeleton skel-card" style="height:280px;"></div>'.repeat(4)}</div>
        `;
    }

    // ------------------------------------------------------------------
    // DATA LAYER — fetched once, cached, reused by every panel. Cheap to
    // refetch (force=true) after something changes the underlying data,
    // e.g. returning from casting a vote.
    // ------------------------------------------------------------------
    let dashboardData = null;
    async function loadDashboardData(force) {
        if (!force && dashboardData) return dashboardData;
        const res = await fetch(DASHBOARD_DATA_API);
        if (!res.ok) throw new Error('Failed to load dashboard data');
        dashboardData = await res.json();
        return dashboardData;
    }

    // ------------------------------------------------------------------
    // NAV / PANEL SWITCHING
    // ------------------------------------------------------------------
    const panelTitles = { home: 'Welcome, ' + STUDENT_NAME, vote: 'Vote', candidates: 'Candidates', results: 'Live Results' };
    let currentPanel = 'home';

    function showPanel(name) {
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        const panelEl = document.getElementById('panel-' + name);
        if (panelEl) panelEl.classList.add('active');
        document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
        const navEl = document.querySelector('.nav-link[data-panel="' + name + '"]');
        if (navEl) navEl.classList.add('active');
        document.getElementById('pageHeaderTitle').textContent = panelTitles[name] || ('Welcome, ' + STUDENT_NAME);
        currentPanel = name;
        toggleSidebar(false);
        window.scrollTo(0, 0);

        if (name === 'home') renderHomePanel();
        if (name === 'vote') renderVotePanel();
        if (name === 'candidates') renderCandidatesPanel();
        if (name === 'results') renderResultsPanel(null); // plain nav click always shows all results, not a leftover filter from a "View Results" deep link
    }

    // ------------------------------------------------------------------
    // ELECTION CARD (shared by Home and the Vote picker — identical
    // markup/logic to what used to be duplicated twice in PHP)
    // ------------------------------------------------------------------
    function renderElectionCard(election, now, countdownIdPrefix) {
        const config = election.config;
        const { isOpen, statusText, statusClass, start, end } = electionUiStatus(config, now);
        const diff = isOpen ? (end - now) / 1000 : 0;
        const canVote = isOpen && !election.hasVoted && election.totalPositions > 0;
        const hasOpened = now >= start;
        const answeredIds = answeredPositionIdsFor(election);
        const answeredCount = election.positions.filter(p => answeredIds.includes(p.position_id)).length;

        let metaHtml = `<span class="ec-meta-item">📅 ${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} &ndash; ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>`;
        if (isOpen) {
            metaHtml += `<span class="ec-meta-item ec-meta-countdown">⏱ <span id="${countdownIdPrefix}_${election.id}">${formatCountdown(diff)}</span></span>`;
        } else if (now < start) {
            metaHtml += `<span class="ec-meta-item">🚀 Starts ${start.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>`;
        }

        let bodyHtml = '';
        if (election.totalPositions === 0) {
            bodyHtml = `<div class="ec-status-neutral">No positions have been added to this election yet.</div>`;
        } else if (hasOpened) {
            bodyHtml = renderBallotProgressStepper(election.positions, answeredIds, election.hasVoted);
            if (election.hasVoted) {
                bodyHtml += `<div class="ec-voted-banner">✅ <span>Thank you &mdash; your vote has been recorded.</span></div>`;
            } else if (config.status === 'paused') {
                bodyHtml += `<div class="ec-status-neutral">Voting is temporarily paused. Check back soon.</div>`;
            } else if (!isOpen && now >= end) {
                bodyHtml += `<div class="ec-status-neutral">You didn't cast a vote before this election closed.</div>`;
            }
        }

        let primaryLabel = null, primaryHref = null;
        if (canVote) {
            primaryLabel = answeredCount > 0 ? '🗳 Continue Voting' : '🗳 Vote Now';
            primaryHref = `dashboard.php?page=vote&election_id=${election.id}`;
        } else if (election.totalPositions > 0 && hasOpened) {
            primaryLabel = '📊 View Results';
        }

        return `
        <div class="election-card status-${electionStatusKey(isOpen, statusClass)}" style="--ec-accent: ${config.accent};">
            <div class="ec-header">
                <div class="ec-icon">${config.icon}</div>
                <div class="ec-heading">
                    <h2 class="election-title">${escHtml(config.name)}</h2>
                    <p class="ec-subtitle">${escHtml(config.subtitle)}</p>
                </div>
                <div class="status-large ${statusClass}"><span class="status-dot"></span>${statusText}</div>
            </div>
            <div class="ec-meta">${metaHtml}</div>
            <div class="ec-divider"></div>
            ${bodyHtml}
            <div class="card-actions${primaryLabel ? '' : ' single'}">
                <button type="button" class="btn btn-secondary" onclick="showCandidatesFor(${election.id})">View Candidates</button>
                ${primaryLabel ? (primaryHref
                    ? `<a href="${primaryHref}" class="btn btn-primary">${primaryLabel}</a>`
                    : `<button type="button" class="btn btn-primary" onclick="showResultsFor(${election.id})">${primaryLabel}</button>`) : ''}
            </div>
        </div>`;
    }

    function startCountdowns(elections, now0, idPrefix) {
        elections.forEach(election => {
            const { isOpen, end } = electionUiStatus(election.config, now0);
            if (!isOpen) return;
            const el = document.getElementById(`${idPrefix}_${election.id}`);
            if (!el) return;
            const endTime = end.getTime();
            function update() {
                const diff = (endTime - Date.now()) / 1000;
                el.textContent = formatCountdown(diff);
            }
            update();
            setInterval(update, 1000);
        });
    }

    function renderElectionFilterBarAndGroups(elections, now, scope, countdownPrefix) {
        const groups = groupElections(elections, now);
        const showHeaders = groups.length > 1;

        let filterBar = '';
        if (showHeaders) {
            const total = elections.length;
            filterBar = `<div class="election-filter-bar" id="${scope}ElectionFilterBar">
                <button type="button" class="selected" onclick="filterElectionGroups('${scope}', 'all', this)">All <span class="fbadge">${total}</span></button>
                ${groups.map(g => `
                    <button type="button" onclick="filterElectionGroups('${scope}', '${g.key}', this)">
                        <span class="dot" style="background: ${GROUP_DOT_COLOR[g.key]};"></span>
                        ${GROUP_LABELS[g.key]} <span class="fbadge">${g.elections.length}</span>
                    </button>`).join('')}
            </div>`;
        }

        const groupsHtml = groups.map(g => `
            ${showHeaders ? `
            <div class="election-group-header" data-group="${g.key}">
                <span class="dot ${g.key}"></span>
                <span class="label">${GROUP_LABELS[g.key]}</span>
                <span class="count">${g.elections.length}</span>
            </div>` : ''}
            <div class="election-cards" data-group="${g.key}">
                ${g.elections.map(e => renderElectionCard(e, now, countdownPrefix)).join('')}
            </div>
        `).join('');

        return { filterBar, groupsHtml, groups };
    }

    function filterElectionGroups(scope, filterKey, btnEl) {
        const bar = document.getElementById(scope + 'ElectionFilterBar');
        if (bar) bar.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
        if (btnEl) btnEl.classList.add('selected');
        const container = document.getElementById(scope + 'ElectionGroups');
        if (!container) return;
        container.querySelectorAll('[data-group]').forEach(el => {
            el.style.display = (filterKey === 'all' || el.dataset.group === filterKey) ? '' : 'none';
        });
    }

    function showCandidatesFor(electionId) {
        showPanel('candidates');
        setTimeout(() => {
            const target = document.getElementById('cand-sec-' + electionId);
            if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
    }
    function showResultsFor(electionId) {
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-results').classList.add('active');
        document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
        const navEl = document.querySelector('.nav-link[data-panel="results"]');
        if (navEl) navEl.classList.add('active');
        document.getElementById('pageHeaderTitle').textContent = panelTitles.results;
        currentPanel = 'results';
        toggleSidebar(false);
        window.scrollTo(0, 0);
        renderResultsPanel(electionId); // scoped to just this election, with a "Back to all results" link — matches the old per-page ?election_id= deep link
    }

    // ------------------------------------------------------------------
    // HOME PANEL
    // ------------------------------------------------------------------
    async function renderHomePanel() {
        const el = document.getElementById('panel-home');
        el.innerHTML = skeletonHome();
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load your dashboard. Please refresh.</div>';
            return;
        }

        const now = new Date();
        const elections = data.elections;

        // Pick ONE election to feature in the hero card, same priority
        // order as the old PHP version:
        //   1) open + not yet voted (soonest deadline first)
        //   2) open + already voted
        //   3) soonest upcoming
        let heroActionable = null, heroVoted = null, heroUpcoming = null;
        elections.forEach(election => {
            const { isOpen, start, end } = electionUiStatus(election.config, now);
            if (isOpen && !election.hasVoted && election.totalPositions > 0) {
                if (!heroActionable || end < heroActionable.end) heroActionable = { election, start, end };
            } else if (isOpen) {
                if (!heroVoted || end < heroVoted.end) heroVoted = { election, end };
            } else if (now < start && election.config.status === 'scheduled') {
                if (!heroUpcoming || start < heroUpcoming.start) heroUpcoming = { election, start };
            }
        });

        let heroHtml = '';
        if (heroActionable) {
            const { election, start, end } = heroActionable;
            const answeredIds = answeredPositionIdsFor(election);
            const answeredCount = election.positions.filter(p => answeredIds.includes(p.position_id)).length;
            const diff = (end - now) / 1000;
            heroHtml = `
            <div class="vote-hero">
                <div class="vote-hero-eyebrow">
                    <div class="ec-header" style="margin-bottom: 0;">
                        <div class="ec-icon">${election.config.icon}</div>
                        <div class="ec-heading">
                            <h2 class="vote-hero-title">${escHtml(election.config.name)}</h2>
                            <p class="ec-subtitle">${escHtml(election.config.subtitle)}</p>
                        </div>
                    </div>
                    <span class="status-large"><span class="status-dot"></span>Voting Open</span>
                </div>
                <div class="ec-meta" style="margin: 0 0 1.25rem;">
                    <span class="ec-meta-item">📅 ${start.toLocaleDateString('en-US',{month:'short',day:'numeric'})} &ndash; ${end.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}</span>
                    <span class="ec-meta-item ec-meta-countdown">⏱ <span id="heroCountdown">${formatCountdown(diff)}</span></span>
                </div>
                ${answeredCount > 0 ? `<div style="margin-bottom: 1.25rem;">${renderBallotProgressStepper(election.positions, answeredIds, false)}</div>` : ''}
                <div class="vote-hero-cta">
                    <a href="dashboard.php?page=vote&election_id=${election.id}" class="btn btn-primary">${answeredCount > 0 ? '🗳 Continue Voting' : '🗳 Cast Your Vote'}</a>
                    <button type="button" class="btn btn-secondary" onclick="showCandidatesFor(${election.id})">View Candidates</button>
                </div>
            </div>`;
        } else if (heroVoted) {
            const { election } = heroVoted;
            heroHtml = `
            <div class="vote-hero is-voted">
                <div class="vote-hero-eyebrow"><span class="status-large"><span class="status-dot"></span>Vote Submitted</span></div>
                <h2 class="vote-hero-title">${escHtml(election.config.name)}</h2>
                <p class="vote-hero-dates">Thanks for participating — your ballot has been recorded. Results will be available once they're released.</p>
                <div class="vote-hero-cta">
                    <button type="button" class="btn btn-primary" onclick="showPanel('results')">📊 View Results</button>
                    <button type="button" class="btn btn-secondary" onclick="showCandidatesFor(${election.id})">View Candidates</button>
                </div>
            </div>`;
        } else if (heroUpcoming) {
            const { election, start } = heroUpcoming;
            heroHtml = `
            <div class="vote-hero">
                <div class="vote-hero-eyebrow"><span class="status-large scheduled"><span class="status-dot"></span>Upcoming</span></div>
                <h2 class="vote-hero-title">${escHtml(election.config.name)}</h2>
                <p class="vote-hero-dates">Voting begins ${start.toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'})}</p>
                <div class="vote-hero-cta">
                    <button type="button" class="btn btn-primary" onclick="showCandidatesFor(${election.id})">View Candidates</button>
                </div>
            </div>`;
        } else if (elections.length > 0) {
            heroHtml = `
            <div class="vote-hero is-empty">
                <div class="vote-hero-empty-icon">📊</div>
                <div class="vote-hero-empty-title">No voting open right now</div>
                <p class="vote-hero-empty-text">Check the results of past elections below.</p>
            </div>`;
        } else {
            heroHtml = `
            <div class="vote-hero is-empty">
                <div class="vote-hero-empty-icon">🗳️</div>
                <div class="vote-hero-empty-title">No elections yet</div>
                <p class="vote-hero-empty-text">Check back soon — you'll see it here the moment voting opens.</p>
            </div>`;
        }

        const quickActionsHtml = `
        <div class="quick-actions">
            <button type="button" class="quick-action-tile" onclick="showPanel('vote')"><div class="qa-icon">🗳</div><div class="qa-label">Vote</div><div class="qa-desc">Cast your ballot</div></button>
            <button type="button" class="quick-action-tile" onclick="showPanel('candidates')"><div class="qa-icon">👥</div><div class="qa-label">Candidates</div><div class="qa-desc">View all candidates</div></button>
            <button type="button" class="quick-action-tile" onclick="showPanel('results')"><div class="qa-icon">📊</div><div class="qa-label">Results</div><div class="qa-desc">Live vote counts</div></button>
        </div>`;

        let electionsSectionHtml = '<div class="alert alert-info">No elections have been set up for you yet. Check back soon.</div>';
        if (elections.length > 0) {
            const { filterBar, groupsHtml } = renderElectionFilterBarAndGroups(elections, now, 'home', 'countdown');
            electionsSectionHtml = `${filterBar}<div id="homeElectionGroups">${groupsHtml}</div>`;
        }

        // Featured candidates — flatten every election's candidates, shuffle, cap at 12
        const featuredPool = [];
        elections.forEach(election => {
            election.positions.forEach(pos => {
                (pos.candidates || []).forEach(c => featuredPool.push({ candidate: c, position: pos.title }));
            });
        });
        for (let i = featuredPool.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [featuredPool[i], featuredPool[j]] = [featuredPool[j], featuredPool[i]];
        }
        const featured = featuredPool.slice(0, 12);
        const featuredHtml = featured.length ? `
        <div class="featured-section">
            <div class="featured-header">
                <h3>Featured Candidates</h3>
                <button type="button" class="btn btn-secondary" style="padding:0.3rem 1rem; font-size:0.85rem;" onclick="showPanel('candidates')">View All</button>
            </div>
            <div class="featured-carousel-viewport" id="featuredViewport">
                <div class="featured-carousel-track" id="featuredTrack"></div>
            </div>
        </div>` : '';

        el.innerHTML = `
            <div class="home-greeting"><p>Here's your election dashboard.</p></div>
            ${heroHtml}
            ${quickActionsHtml}
            <h2 class="section-title">Your Elections</h2>
            ${electionsSectionHtml}
            ${featuredHtml}
        `;

        if (heroActionable) {
            const endTime = heroActionable.end.getTime();
            const countdownEl = document.getElementById('heroCountdown');
            if (countdownEl) setInterval(() => { countdownEl.textContent = formatCountdown((endTime - Date.now()) / 1000); }, 1000);
        }
        startCountdowns(elections, now, 'countdown');
        if (featured.length) startFeaturedCarousel(featured);
    }

    // ------------------------------------------------------------------
    // VOTE PICKER PANEL (the actual per-election ballot itself stays a
    // full page load at dashboard.php?page=vote&election_id=X — see the
    // "Vote Now" links above; this panel is just the "choose which
    // election" screen, same content as before, just client-rendered)
    // ------------------------------------------------------------------
    async function renderVotePanel() {
        const el = document.getElementById('panel-vote');
        el.innerHTML = skeletonCards();
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load elections. Please refresh.</div>';
            return;
        }

        const now = new Date();
        const elections = data.elections;

        if (!elections.length) {
            el.innerHTML = `<h2>Choose an Election to Vote In</h2><div class="alert alert-info">No elections are available to you right now.</div>`;
            return;
        }

        const { filterBar, groupsHtml } = renderElectionFilterBarAndGroups(elections, now, 'vote', 'countdown_vote');
        el.innerHTML = `
            <h2>Choose an Election to Vote In</h2>
            ${filterBar}
            <div id="voteElectionGroups">${groupsHtml}</div>
        `;
        startCountdowns(elections, now, 'countdown_vote');
    }

    // ------------------------------------------------------------------
    // CANDIDATES PANEL (magazine-style, grouped by election -> position)
    // ------------------------------------------------------------------
    async function renderCandidatesPanel() {
        const el = document.getElementById('panel-candidates');
        el.innerHTML = skeletonCandidates();
        window.__candMap = {};
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load candidates. Please refresh.</div>';
            return;
        }

        const sections = [];
        const partySet = new Set();
        data.elections.forEach(election => {
            const positions = [];
            election.positions.forEach(pos => {
                const cands = pos.candidates || [];
                if (!cands.length) return;
                cands.forEach(c => { if (c.party && c.party !== 'No Party / Independent') partySet.add(c.party); });
                positions.push({ position: pos, candidates: cands });
            });
            if (positions.length) sections.push({ election, positions });
        });
        const allParties = Array.from(partySet).sort();

        if (!sections.length) {
            el.innerHTML = `
                <div class="cand-page">
                    <div class="cand-header"><h2 class="cand-title">Meet the Candidates</h2><p class="muted">Browse everyone on the ballot, grouped by election and position.</p></div>
                    <div class="alert alert-info">No candidates to show yet.</div>
                </div>`;
            return;
        }

        const toolbarHtml = `
            <div class="cand-toolbar">
                <div class="cand-search-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="candSearch" placeholder="Search candidates, platforms, courses…" oninput="filterCandidates()">
                </div>
                ${allParties.length ? `
                <select id="candPartyFilter" onchange="filterCandidates()">
                    <option value="">All Parties</option>
                    ${allParties.map(p => `<option value="${escHtml(p)}">${escHtml(p)}</option>`).join('')}
                </select>` : ''}
            </div>`;

        const jumpNavHtml = sections.length > 1 ? `
            <nav class="cand-jump-nav" id="candJumpNav">
                ${sections.map(sec => `<a href="#cand-sec-${sec.election.id}" class="cand-jump-link" data-target="cand-sec-${sec.election.id}">${escHtml(sec.election.config.name)}</a>`).join('')}
            </nav>` : '';

        const sectionsHtml = sections.map(sec => `
            <section class="cand-election-section" id="cand-sec-${sec.election.id}">
                <div class="cand-election-head">
                    <span class="cand-election-icon">${sec.election.config.icon}</span>
                    <div><h3>${escHtml(sec.election.config.name)}</h3><p>${escHtml(sec.election.config.subtitle)}</p></div>
                </div>
                ${sec.positions.map(posBlock => {
                    const position = posBlock.position;
                    return `
                    <div class="cand-position-block">
                        <h4 class="cand-position-title">
                            ${escHtml(position.title)}
                            ${position.year_restriction ? `<span class="cand-position-note">Visible to ${escHtml(position.year_restriction)}</span>` : ''}
                        </h4>
                        <div class="cand-grid">
                            ${posBlock.candidates.map(c => {
                                const party = (c.party && c.party !== 'No Party / Independent') ? c.party : '';
                                const photo = c.photo || 'assets/default-avatar.png';
                                const platform = (c.platform || '').trim();
                                const searchMeta = `${c.course || ''} ${c.year_level || ''} ${platform}`.toLowerCase().trim();
                                window.__candMap[c.id] = {
                                    ...c,
                                    party,
                                    photo,
                                    platform,
                                    positionTitle: position.title,
                                    electionName: sec.election.config.name,
                                };
                                return `
                                <article class="cand-card" data-name="${escHtml((c.name||'').toLowerCase())}" data-party="${escHtml(party)}" data-meta="${escHtml(searchMeta)}">
                                    <div class="cand-card-photo" role="button" tabindex="0" aria-label="View ${escHtml(c.name)}'s full profile" onclick="openCandidateModal(${c.id})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openCandidateModal(${c.id});}">
                                        <img src="${escHtml(photo)}" alt="${escHtml(c.name)}" loading="lazy">
                                        <div class="cand-card-scrim">
                                            ${party ? `<span class="cand-card-kicker">${escHtml(party)}</span>` : ''}
                                            <span class="cand-card-name">${escHtml(c.name)}</span>
                                        </div>
                                    </div>
                                    <div class="cand-card-body">
                                        <div class="cand-card-meta">
                                            ${c.course ? `<span>${escHtml(c.course)}</span>` : ''}
                                            ${c.year_level ? `<span>${escHtml(c.year_level)}</span>` : ''}
                                        </div>
                                        ${platform ? `<p class="cand-card-platform">${escHtml(platform)}</p>` : `<p class="cand-card-platform muted" style="-webkit-line-clamp:unset;">No platform statement provided.</p>`}
                                        <button type="button" class="cand-card-toggle" onclick="openCandidateModal(${c.id})">View Full Profile</button>
                                    </div>
                                </article>`;
                            }).join('')}
                        </div>
                    </div>`;
                }).join('')}
            </section>
        `).join('');

        el.innerHTML = `
            <div class="cand-page">
                <div class="cand-header"><h2 class="cand-title">Meet the Candidates</h2><p class="muted">Browse everyone on the ballot, grouped by election and position.</p></div>
                ${toolbarHtml}
                ${jumpNavHtml}
                <div id="candNoResults" class="cand-no-results" style="display:none;">No candidates match your search.</div>
                ${sectionsHtml}
            </div>
        `;
        wireCandidateJumpNav();
    }

    function filterCandidates() {
        const q = (document.getElementById('candSearch')?.value || '').trim().toLowerCase();
        const partyFilter = document.getElementById('candPartyFilter')?.value || '';
        let anyVisible = false;
        document.querySelectorAll('.cand-card').forEach(card => {
            const name = card.dataset.name || '';
            const meta = card.dataset.meta || '';
            const party = card.dataset.party || '';
            const matchesQuery = !q || name.includes(q) || meta.includes(q) || party.toLowerCase().includes(q);
            const matchesParty = !partyFilter || party === partyFilter;
            const show = matchesQuery && matchesParty;
            card.style.display = show ? '' : 'none';
            if (show) anyVisible = true;
        });
        document.querySelectorAll('.cand-position-block').forEach(block => {
            block.style.display = block.querySelector('.cand-card:not([style*="display: none"])') ? '' : 'none';
        });
        document.querySelectorAll('.cand-election-section').forEach(sec => {
            sec.style.display = sec.querySelector('.cand-position-block:not([style*="display: none"])') ? '' : 'none';
        });
        const noResults = document.getElementById('candNoResults');
        if (noResults) noResults.style.display = anyVisible ? 'none' : 'block';
    }

    // Full-profile modal: photo + identifying info on the left, platform
    // statement filling the center-to-right. Triggered by clicking either
    // the candidate's photo or the "View Full Profile" button.
    function openCandidateModal(candidateId) {
        const c = (window.__candMap || {})[candidateId];
        if (!c) return;

        closeCandidateModal(); // guard against a stray leftover instance

        const overlay = document.createElement('div');
        overlay.className = 'cand-modal-overlay';
        overlay.id = 'candModalOverlay';
        overlay.innerHTML = `
            <div class="cand-modal" role="dialog" aria-modal="true" aria-label="${escHtml(c.name)}'s profile">
                <button type="button" class="cand-modal-close" aria-label="Close" onclick="closeCandidateModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div class="cand-modal-left">
                    <div class="cand-modal-photo"><img src="${escHtml(c.photo)}" alt="${escHtml(c.name)}"></div>
                    <div class="cand-modal-info">
                        <h2 class="cand-modal-name">${escHtml(c.name)}</h2>
                        ${c.party ? `<span class="cand-modal-party">${escHtml(c.party)}</span>` : ''}
                        <div class="cand-modal-meta-list">
                            <div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Running for</span><span>${escHtml(c.positionTitle)}</span></div>
                            <div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Election</span><span>${escHtml(c.electionName)}</span></div>
                            ${c.course ? `<div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Course</span><span>${escHtml(c.course)}</span></div>` : ''}
                            ${c.year_level ? `<div class="cand-modal-meta-row"><span class="cand-modal-meta-label">Year Level</span><span>${escHtml(c.year_level)}</span></div>` : ''}
                        </div>
                    </div>
                </div>
                <div class="cand-modal-right">
                    <h3 class="cand-modal-platform-label">Platform</h3>
                    <div class="cand-modal-platform-text">${c.platform ? escHtml(c.platform) : '<span class="muted">No platform statement provided.</span>'}</div>
                </div>
            </div>`;

        function onKey(e) { if (e.key === 'Escape') closeCandidateModal(); }
        overlay.addEventListener('click', e => { if (e.target === overlay) closeCandidateModal(); });
        overlay._onKey = onKey;
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        overlay.querySelector('.cand-modal-close').focus();
    }

    function closeCandidateModal() {
        const overlay = document.getElementById('candModalOverlay');
        if (!overlay) return;
        if (overlay._onKey) document.removeEventListener('keydown', overlay._onKey);
        overlay.remove();
        document.body.style.overflow = '';
    }

    function wireCandidateJumpNav() {
        const nav = document.getElementById('candJumpNav');
        if (!nav) return;
        const links = Array.from(nav.querySelectorAll('.cand-jump-link'));
        links.forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                const target = document.getElementById(link.dataset.target);
                if (!target) return;
                // Highlight immediately on click rather than waiting for the
                // scroll-position observer below to catch up — the LAST
                // section in the list often can't reliably trigger that
                // observer at all, since there's not always enough page
                // left below it to scroll it into the detection band. A
                // click should always give clear feedback regardless of
                // where the section happens to sit on the page.
                links.forEach(l => l.classList.remove('active'));
                link.classList.add('active');
                const offset = nav.getBoundingClientRect().height + 84;
                const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top, behavior: 'smooth' });
            });
        });
        if ('IntersectionObserver' in window) {
            const sections = links.map(l => document.getElementById(l.dataset.target)).filter(Boolean);
            const io = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        links.forEach(l => l.classList.remove('active'));
                        const active = links.find(l => l.dataset.target === entry.target.id);
                        if (active) active.classList.add('active');
                    }
                });
            }, { rootMargin: '-35% 0px -55% 0px', threshold: 0 });
            sections.forEach(s => io.observe(s));

            // Fallback for exactly that "last section" case: if the page is
            // scrolled to (or near) the very bottom, force the last link
            // active even if its section never entered the observer's
            // narrow detection band.
            window.addEventListener('scroll', () => {
                const atBottom = window.innerHeight + window.scrollY >= document.body.scrollHeight - 4;
                if (atBottom && links.length) {
                    links.forEach(l => l.classList.remove('active'));
                    links[links.length - 1].classList.add('active');
                }
            }, { passive: true });
        }
    }

    // ------------------------------------------------------------------
    // RESULTS PANEL
    // ------------------------------------------------------------------
    let resultsFilterElectionId = null;

    async function renderResultsPanel(filterElectionId) {
        if (filterElectionId !== undefined) resultsFilterElectionId = filterElectionId;
        const el = document.getElementById('panel-results');
        el.innerHTML = skeletonCards();
        let data;
        try {
            data = await loadDashboardData();
        } catch (e) {
            el.innerHTML = '<div class="alert alert-error">Could not load results. Please refresh.</div>';
            return;
        }

        let scopedElections = data.elections;
        let notFound = false;
        if (resultsFilterElectionId) {
            scopedElections = data.elections.filter(e => e.id === resultsFilterElectionId);
            if (!scopedElections.length) notFound = true;
        }

        const visibleElections = scopedElections.filter(election => {
            const config = election.config;
            const visible = config.results_visibility === 'always'
                || (config.results_visibility === 'after' && ['closed', 'archived'].includes(config.status));
            return visible && election.positions.length > 0;
        });

        const backLink = resultsFilterElectionId
            ? `<button type="button" class="btn btn-secondary" style="margin-bottom:1.25rem;display:inline-flex;" onclick="renderResultsPanel(null)">&larr; Back to all results</button>`
            : '';

        if (notFound) {
            el.innerHTML = `<h2>Live Results</h2><p class="muted">Results update automatically every 15 seconds.</p>${backLink}<div class="alert alert-error">That election could not be found.</div>`;
            return;
        }
        if (!visibleElections.length) {
            el.innerHTML = `<h2>Live Results</h2><p class="muted">Results update automatically.</p>${backLink}<div class="alert alert-info">Results are not available yet.</div>`;
            return;
        }

        const html = visibleElections.map(election => {
            const positionsHtml = election.positions.map(position => {
                const candidates = position.candidates || [];
                const totalVotes = candidates.reduce((sum, c) => sum + (c.vote_count || 0), 0);
                const rowsHtml = candidates.length ? candidates.map(c => {
                    const pct = totalVotes > 0 ? Math.round((c.vote_count / totalVotes) * 1000) / 10 : 0;
                    return `
                    <div class="result-row">
                        <div class="result-header">
                            <span class="candidate-name">${escHtml(c.name)}</span>
                            <span class="vote-count">${c.vote_count} vote${c.vote_count === 1 ? '' : 's'} (${pct}%)</span>
                        </div>
                        <div class="progress-bar-track"><div class="progress-bar-fill" style="width: ${pct}%;"></div></div>
                    </div>`;
                }).join('') : '<p class="muted">No candidates.</p>';
                return `
                <div class="results-block">
                    <h3>${escHtml(position.title)}</h3>
                    ${rowsHtml}
                    <p class="muted">Total votes: ${totalVotes}</p>
                </div>`;
            }).join('');
            return `<h2>${escHtml(election.config.name)}</h2>${positionsHtml}<hr>`;
        }).join('');

        el.innerHTML = `<h2>Live Results</h2><p class="muted">Results update automatically every 15 seconds.</p>${backLink}${html}`;

        // Refresh vote counts on an interval, matching the old page's
        // meta-refresh behavior — but now it's just a data refetch + partial
        // re-render instead of a full page reload.
        clearInterval(window.__resultsRefreshTimer);
        window.__resultsRefreshTimer = setInterval(() => {
            if (currentPanel === 'results') renderResultsPanel();
        }, 15000);
    }

    // ------------------------------------------------------------------
    // FEATURED CANDIDATES CAROUSEL (ported as-is from the previous version)
    // ------------------------------------------------------------------
    function startFeaturedCarousel(featuredCandidates) {
        const viewport = document.getElementById('featuredViewport');
        const track = document.getElementById('featuredTrack');
        if (!viewport || !track || !featuredCandidates.length) return;

        const MIN_SCALE = 0.72, MAX_SCALE = 1.28, BASE_SPEED = 40, SPEED_VARIANCE = 0.85;
        const setLength = featuredCandidates.length;
        const renderList = [...featuredCandidates, ...featuredCandidates, ...featuredCandidates];

        track.innerHTML = renderList.map(item => {
            const c = item.candidate;
            const photo = c.photo || 'assets/default-avatar.png';
            return `
            <div class="fc-card">
                <img src="${escHtml(photo)}" alt="${escHtml(c.name)}">
                <div class="fc-name">${escHtml(c.name)}</div>
                <div class="fc-position">${escHtml(item.position)}</div>
            </div>`;
        }).join('');

        const cards = Array.from(track.children);
        let cardWidth = 0, trackPosition = 0, paused = false, lastTime = null;
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
            if (document.getElementById('featuredViewport')) requestAnimationFrame(tick);
        }
        measure();
        window.addEventListener('resize', measure);
        viewport.addEventListener('mouseenter', () => { paused = true; });
        viewport.addEventListener('mouseleave', () => { paused = false; });
        requestAnimationFrame(tick);
    }

    // ------------------------------------------------------------------
    // INIT — figure out which panel to open based on ?page= in the URL
    // (so a bookmarked/shared link like dashboard.php?page=results still
    // lands on the right panel), then render it.
    // ------------------------------------------------------------------
    (function init() {
        const params = new URLSearchParams(window.location.search);
        const requestedPage = params.get('page');
        const validPanels = ['home', 'vote', 'candidates', 'results'];
        const initial = validPanels.includes(requestedPage) ? requestedPage : 'home';
        showPanel(initial);
    })();
    </script>
    </body>
    </html>