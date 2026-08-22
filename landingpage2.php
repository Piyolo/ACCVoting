<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();

$pdo = getDbConnection();
syncElectionStatuses($pdo);
$isLoggedIn = !empty($_SESSION['user_id']);

// Fetch current elections (non‑draft)
$elections = $pdo->query("
    SELECT name, type, department, status, start_date, end_date
    FROM elections
    WHERE status != 'draft'
    ORDER BY start_date ASC
")->fetchAll();

// Fetch candidates for the carousel (limit to 12)
$candidates = [];
$candStmt = $pdo->prepare('
    SELECT c.id, c.name, c.photo, p.title AS position_title
    FROM candidates c
    JOIN positions p ON p.id = c.position_id
    JOIN election_positions ep ON ep.position_id = p.id
    JOIN elections e ON e.id = ep.election_id
    WHERE e.status != \'draft\'
    ORDER BY RANDOM()
    LIMIT 12
');
$candStmt->execute();
$candidates = $candStmt->fetchAll();

// Helper for status classes
function statusLabel($s) {
    return ['draft'=>'Not Started','scheduled'=>'Upcoming','ongoing'=>'Ongoing','paused'=>'Paused','closed'=>'Ended','archived'=>'Archived'][$s] ?? $s;
}
function statusClass($s) {
    return ['draft'=>'scheduled','scheduled'=>'scheduled','ongoing'=>'ongoing','paused'=>'paused','closed'=>'closed','archived'=>'closed'][$s] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aklan Catholic College – School Elections</title>
<link rel="stylesheet" href="assets/style.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    background: #f8fafc;
    color: #0f172a;
    overflow-y: scroll;
    scroll-snap-type: y mandatory;
    height: 100vh;
}

/* ---------- TOP BAR (sticky) ---------- */
.topbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(4px);
    border-bottom: 1px solid #e2e8f0;
    padding: 0.5rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem 1rem;
}
.topbar .brand {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.topbar .brand img {
    height: 48px;
    width: auto;
    display: block;
}
.topbar .brand .name {
    font-weight: 700;
    font-size: 1.05rem;
    color: #0f172a;
    line-height: 1.3;
}
.topbar .brand .name small {
    font-weight: 400;
    font-size: 0.75rem;
    color: #64748b;
    display: block;
}
.topbar .nav-links {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    flex-wrap: wrap;
}
.topbar .nav-links a {
    padding: 0.5rem 1rem;
    border-radius: 999px;
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
    color: #1e293b;
}
.topbar .nav-links a:hover { background: #f1f5f9; }
.topbar .nav-links a.primary {
    background: #84cc16;
    color: #052e16;
}
.topbar .nav-links a.primary:hover { background: #65a30d; }
.topbar .nav-links a.outline {
    border: 2px solid #e2e8f0;
}
.topbar .nav-links a.outline:hover { border-color: #94a3b8; }

/* ---------- SNAP SECTIONS (full viewport) ---------- */
.snap-section {
    height: 100vh;
    scroll-snap-align: start;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 2rem 1.5rem;
    overflow-y: auto;
    position: relative;
}

/* ----- HERO (section 1) with animations ----- */
.hero {
    background: linear-gradient(145deg, #f1f5f9 0%, #ffffff 100%);
    text-align: center;
    overflow: hidden;
}
/* Ballot cascade: paper slips with a lime checkmark fall at staggered
   speeds/rotations and disappear into the ballot box at the bottom —
   replaces the old floating-emoji animation with an on-brand illustration
   built from the actual product (a ballot), not decorative emoji. */
.hero .ballot-cascade {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
}
.hero .ballot-slip {
    position: absolute;
    top: -60px;
    width: 26px;
    height: 34px;
    background: #ffffff;
    border-radius: 3px;
    box-shadow: 0 3px 8px rgba(15, 23, 42, 0.12);
    opacity: 0;
    animation: ballotFall linear infinite;
}
.hero .ballot-slip::after {
    content: '';
    position: absolute;
    left: 6px;
    top: 12px;
    width: 12px;
    height: 8px;
    border-left: 2.5px solid #84cc16;
    border-bottom: 2.5px solid #84cc16;
    transform: rotate(-45deg);
}
@keyframes ballotFall {
    0% { transform: translateY(0) rotate(0deg); opacity: 0; }
    8% { opacity: 0.9; }
    88% { opacity: 0.9; }
    100% { transform: translateY(115vh) rotate(200deg); opacity: 0; }
}
.hero .hero-ballotbox {
    position: absolute;
    bottom: 8%;
    left: 50%;
    transform: translateX(-50%);
    width: 130px;
    height: 60px;
    pointer-events: none;
}
.hero .hero-ballotbox .hbb-body {
    position: absolute;
    bottom: 0;
    width: 130px;
    height: 46px;
    background: #1e293b;
    border: 2px solid #d4af37;
    border-radius: 6px;
    opacity: 0.9;
}
.hero .hero-ballotbox .hbb-slot {
    position: absolute;
    top: -8px;
    left: 50%;
    transform: translateX(-50%);
    width: 48px;
    height: 8px;
    background: #0f172a;
    border: 2px solid #d4af37;
    border-radius: 3px;
}

.hero h1 {
    font-size: clamp(2.8rem, 7vw, 5rem);
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.1;
    position: relative;
    z-index: 2;
}
.hero h1 .highlight { color: #84cc16; }
.hero p {
    max-width: 600px;
    margin: 1rem auto 2rem;
    font-size: 1.15rem;
    color: #475569;
    position: relative;
    z-index: 2;
}
.hero .cta-buttons {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
    position: relative;
    z-index: 2;
}
.hero .cta-buttons .btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-sizing: border-box !important;
    height: 56px !important;
    min-height: 56px !important;
    max-height: 56px !important;
    line-height: 56px !important;
    min-width: 200px !important;
    padding: 0 2rem !important;
    margin: 0 !important;
    border-radius: 999px;
    font-weight: 600;
    font-size: 1rem;
    text-decoration: none;
    border: 2px solid transparent;
    transition: transform 0.15s, background 0.2s;
    position: relative;
    vertical-align: middle;
}
.hero .cta-buttons .btn:active { transform: scale(0.97); }
.hero .cta-buttons .btn-primary {
    background: #84cc16;
    color: #052e16;
    border-color: #84cc16;
    /* Symmetric glow (equal on all sides, no vertical offset) so the
       pulse never visually grows this button taller than btn-outline
       next to it. */
    animation: pulseGlow 2s ease-in-out infinite;
}
@keyframes pulseGlow {
    0% { box-shadow: 0 0 0 0 rgba(132,204,22,0.5); }
    70% { box-shadow: 0 0 0 15px rgba(132,204,22,0); }
    100% { box-shadow: 0 0 0 0 rgba(132,204,22,0); }
}
.hero .cta-buttons .btn-primary:hover { background: #65a30d; border-color: #65a30d; animation: none; }
.hero .cta-buttons .btn-outline {
    background: #ffffff;
    color: #1e293b;
    border-color: #94a3b8;
}
.hero .cta-buttons .btn-outline:hover {
    border-color: #1e293b;
    background: #f8fafc;
}

/* ----- MIDDLE (section 2): elections + how-to-vote ----- */
.middle {
    background: #f8fafc;
    justify-content: flex-start;
    padding-top: 2rem;
    gap: 1.5rem;
}
.middle .section-title {
    font-size: 2rem;
    font-weight: 700;
    text-align: center;
    margin-bottom: 0.25rem;
}
.middle .section-sub {
    text-align: center;
    color: #64748b;
    margin-bottom: 1rem;
}
.election-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.5rem;
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
}
.election-card {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid #eef1f5;
    box-shadow: 0 3px 12px rgba(15,23,42,0.05);
    padding: 1.5rem 1.6rem;
    border-top: 4px solid #84cc16;
    transition: transform 0.2s;
}
.election-card:hover { transform: translateY(-3px); }
.election-card .status-large {
    display: inline-flex; align-items: center; gap: 0.5rem;
    font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em;
    text-transform: uppercase;
    padding: 0.3rem 0.8rem 0.3rem 0.6rem;
    border-radius: 999px;
    line-height: 1;
}
.election-card .status-large .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.status-large.ongoing { color: #15803d; background: rgba(34,197,94,0.12); }
.status-large.ongoing .dot { background: #22c55e; }
.status-large.scheduled { color: #3730a3; background: rgba(99,102,241,0.12); }
.status-large.scheduled .dot { background: #6366f1; }
.status-large.paused { color: #92400e; background: rgba(245,158,11,0.15); }
.status-large.paused .dot { background: #f59e0b; }
.status-large.closed { color: #b91c1c; background: rgba(239,68,68,0.1); }
.status-large.closed .dot { background: #ef4444; }
.election-card h3 { margin: 0.5rem 0 0.25rem; font-size: 1.05rem; }
.election-card .meta { color: #64748b; font-size: 0.85rem; }

.how-to-vote {
    background: #f1f5f9;
    border-radius: 24px;
    padding: 1.5rem 2rem 2rem;
    text-align: center;
    width: 100%;
    max-width: 1100px;
    margin: 1rem auto 0;
    position: relative;
    overflow: hidden;
}
.how-to-vote::before {
    content: '✓';
    position: absolute;
    font-size: 12rem;
    color: rgba(132,204,22,0.06);
    top: -2rem;
    right: -2rem;
    transform: rotate(15deg);
}
.how-to-vote .steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1.5rem;
    margin-top: 1rem;
    position: relative;
    z-index: 2;
}
.how-to-vote .step .num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #84cc16;
    color: #052e16;
    font-weight: 800;
    font-size: 1.1rem;
    animation: popIn 0.6s ease-out both;
    animation-delay: calc(0.2s * var(--step, 1));
}
.how-to-vote .step:nth-child(1) .num { --step: 1; }
.how-to-vote .step:nth-child(2) .num { --step: 2; }
.how-to-vote .step:nth-child(3) .num { --step: 3; }
@keyframes popIn {
    0% { transform: scale(0); opacity: 0; }
    80% { transform: scale(1.2); }
    100% { transform: scale(1); opacity: 1; }
}
.how-to-vote .step h4 { margin: 0.4rem 0 0.2rem; font-size: 1rem; }
.how-to-vote .step p { color: #475569; font-size: 0.85rem; }

/* ----- BOTTOM (section 3): CTA + footer ----- */
.bottom {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    justify-content: space-between;
    padding: 2rem 1.5rem;
}
.bottom .cta-content {
    text-align: center;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.bottom .cta-content h2 {
    font-size: 2.5rem;
    font-weight: 700;
}
.bottom .cta-content h2 .highlight { color: #84cc16; }
.bottom .cta-content p {
    font-size: 1.1rem;
    color: #cbd5e1;
    margin: 0.5rem 0 2rem;
}
.bottom .cta-content .btn-group {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
}
.bottom .cta-content .btn {
    min-width: 160px;
    padding: 0.75rem 1.8rem;
    border-radius: 999px;
    font-weight: 600;
    text-decoration: none;
    transition: transform 0.15s, background 0.2s;
}
.bottom .cta-content .btn:active { transform: scale(0.97); }
.bottom .cta-content .btn-light {
    background: #ffffff;
    color: #0f172a;
    animation: pulseGlow 2.5s ease-in-out infinite;
}
.bottom .cta-content .btn-light:hover { background: #e2e8f0; animation: none; }
.bottom .cta-content .btn-outline-light {
    background: transparent;
    color: #ffffff;
    border: 2px solid rgba(255,255,255,0.3);
}
.bottom .cta-content .btn-outline-light:hover { background: rgba(255,255,255,0.1); border-color: #ffffff; }

/* Footer inside bottom section */
.footer {
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(255,255,255,0.1);
}
.footer .left {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.footer .left img { height: 48px; width: auto; filter: brightness(0) invert(1); }
.footer .left .info { line-height: 1.4; }
.footer .left .info strong { display: block; font-size: 1rem; }
.footer .left .info small { color: #94a3b8; font-size: 0.8rem; }

.footer .right {
    flex: 1;
    min-width: 180px;
    text-align: right;
}
.footer .right h4 { margin: 0 0 0.3rem; font-size: 0.95rem; color: #cbd5e1; }

/* Candidate carousel (inside footer)
   Sized generously on purpose — content is only ~90px tall even scaled
   up, but a tight-fitting box is exactly what caused clipping before.
   190px gives roughly the same content-to-box ratio as the version that
   never clipped (300px box for an 80px photo). */
.carousel-viewport {
    position: relative;
    overflow: hidden;
    width: 100%;
    height: 190px;
    -webkit-mask-image: linear-gradient(to right, transparent 0%, black 10%, black 90%, transparent 100%);
    mask-image: linear-gradient(to right, transparent 0%, black 10%, black 90%, transparent 100%);
}
.carousel-track {
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
    padding: 10px 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    transform-origin: center center;
    will-change: transform;
}
.fc-card img {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
    background: #f1f5f9;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    margin-bottom: 0.3rem;
}
.fc-card .fc-name {
    font-weight: 600;
    font-size: 0.7rem;
    color: #e2e8f0;
    max-width: 70px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.fc-card .fc-position {
    font-size: 0.6rem;
    color: #94a3b8;
    max-width: 70px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Responsive */
@media (max-width: 768px) {
    .topbar { padding: 0.5rem 1rem; flex-direction: column; align-items: stretch; }
    .topbar .brand { justify-content: center; }
    .topbar .nav-links { justify-content: center; gap: 0.15rem; }
    .topbar .nav-links a { padding: 0.4rem 0.8rem; font-size: 0.8rem; }
    .snap-section { padding: 1.5rem 1rem; }
    .middle { padding-top: 1rem; }
    .election-cards { grid-template-columns: 1fr 1fr; }
    .footer { flex-direction: column; text-align: center; }
    .footer .right { text-align: center; }
    .carousel-viewport { height: 150px; }
    .fc-card img { width: 40px; height: 40px; }
    .bottom .cta-content h2 { font-size: 2rem; }
    .hero .ballot-slip { width: 20px; height: 26px; }
    .hero .hero-ballotbox { bottom: 4%; width: 100px; }
    .hero .hero-ballotbox .hbb-body { width: 100px; height: 38px; }
}
@media (max-width: 480px) {
    .election-cards { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ========== TOP BAR ========== -->
<header class="topbar" id="top">
    <div class="brand">
        <img src="assets/logo.png" alt="ACC Logo">
        <div class="name">
            Aklan Catholic College
            <small>School Elections</small>
        </div>
    </div>
    <nav class="nav-links">
        <a href="#elections">Elections</a>
        <a href="results.php">Results</a>
        <?php if ($isLoggedIn): ?>
            <a href="dashboard.php" class="primary">Dashboard</a>
            <a href="logout.php" class="outline">Log Out</a>
        <?php else: ?>
            <a href="student/register.php" class="outline">Register</a>
            <a href="login.php" class="primary">Log In</a>
        <?php endif; ?>
    </nav>
</header>

<!-- ========== SECTION 1: HERO (animated) ========== -->
<section class="snap-section hero">
    <!-- Ballot cascade: paper slips fall into the ballot box, generated in JS below -->
    <div class="ballot-cascade" id="ballotCascade" aria-hidden="true"></div>
    <div class="hero-ballotbox" aria-hidden="true">
        <div class="hbb-slot"></div>
        <div class="hbb-body"></div>
    </div>

    <h1>
        Your Voice<br>
        <span class="highlight">Your Choice</span>
    </h1>
    <p>Participate in shaping the future of Aklan Catholic College. Cast your vote for the leaders who will represent your department and your school.</p>
    <div class="cta-buttons">
        <a href="<?= $isLoggedIn ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary">🗳 Vote Now</a>
        <a href="results.php" class="btn btn-outline">📊 View Live Results</a>
    </div>
</section>

<!-- ========== SECTION 2: ELECTIONS + HOW TO VOTE ========== -->
<section class="snap-section middle" id="elections">
    <h2 class="section-title">Current Elections</h2>
    <p class="section-sub">Here are the elections you can participate in right now.</p>

    <?php if (empty($elections)): ?>
        <div style="text-align:center; color:#64748b; background:#fff; border-radius:16px; padding:1.5rem; width:100%; max-width:600px; margin:0 auto;">
            No elections are currently open. Check back soon!
        </div>
    <?php else: ?>
        <div class="election-cards">
            <?php foreach ($elections as $e):
                $now = new DateTime();
                $start = new DateTime($e['start_date']);
                $end = new DateTime($e['end_date']);
                $isOpen = ($e['status'] === 'ongoing' && $now >= $start && $now <= $end);
                $statusText = $isOpen ? 'Ongoing' : (in_array($e['status'], ['scheduled','draft']) ? 'Upcoming' : ($e['status'] === 'paused' ? 'Paused' : 'Ended'));
                $statusClass = $isOpen ? 'ongoing' : (in_array($e['status'], ['scheduled','draft']) ? 'scheduled' : ($e['status'] === 'paused' ? 'paused' : 'closed'));
            ?>
                <div class="election-card">
                    <div class="status-large <?= $statusClass ?>">
                        <span class="dot"></span> <?= $statusText ?>
                    </div>
                    <h3><?= htmlspecialchars($e['name']) ?></h3>
                    <div class="meta">
                        <?= $e['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government' . ($e['department'] ? ' — ' . htmlspecialchars($e['department']) : '') ?>
                    </div>
                    <div class="meta" style="font-size:0.75rem; margin-top:0.25rem;">
                        <?= date('M j, Y', $start->getTimestamp()) ?> – <?= date('M j, Y', $end->getTimestamp()) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="how-to-vote">
        <h2 style="font-size:1.5rem; margin-bottom:0.25rem;">How to Vote</h2>
        <p style="color:#64748b; margin-bottom:0.5rem;">It’s quick and secure – just follow these three steps.</p>
        <div class="steps">
            <div class="step">
                <div class="num">1</div>
                <h4>Log In</h4>
                <p>Use your Student ID and password to access your voting dashboard.</p>
            </div>
            <div class="step">
                <div class="num">2</div>
                <h4>Choose Your Candidates</h4>
                <p>Review the candidates for each position and select your preferred leaders.</p>
            </div>
            <div class="step">
                <div class="num">3</div>
                <h4>Submit Your Vote</h4>
                <p>Confirm your choices – your vote is final and completely confidential.</p>
            </div>
        </div>
    </div>
</section>

<!-- ========== SECTION 3: CTA + FOOTER ========== -->
<section class="snap-section bottom">
    <div class="cta-content">
        <h2>
            Vote Wisely<br>
            <span class="highlight">Your Participation Matters</span>
        </h2>
        <p>Every vote counts. Make sure your voice is heard.</p>
        <div class="btn-group">
            <a href="<?= $isLoggedIn ? 'dashboard.php' : 'login.php' ?>" class="btn btn-light">Log In to Vote</a>
            <?php if (!$isLoggedIn): ?>
                <a href="student/register.php" class="btn btn-outline-light">Register Now</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <div class="left">
            <img src="assets/logo.png" alt="ACC Logo">
            <div class="info">
                <strong>Aklan Catholic College</strong>
                <small>Student Elections – © <?= date('Y') ?> School Elections, ACC</small>
            </div>
        </div>
        <div class="right">
            <h4>Candidates</h4>
            <div class="carousel-viewport" id="footerCarousel">
                <div class="carousel-track" id="footerTrack"></div>
            </div>
        </div>
    </div>
</section>

<script>
// Ballot cascade: spawn falling paper slips at random x positions,
// speeds, and delays so they never fall in visible lock-step.
(function() {
    const field = document.getElementById('ballotCascade');
    if (!field) return;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) return;

    const count = window.innerWidth <= 768 ? 10 : 16;
    for (let i = 0; i < count; i++) {
        const slip = document.createElement('div');
        slip.className = 'ballot-slip';
        slip.style.left = (4 + Math.random() * 92) + '%';
        slip.style.animationDuration = (5 + Math.random() * 4) + 's';
        slip.style.animationDelay = '-' + (Math.random() * 9) + 's';
        field.appendChild(slip);
    }
})();

// Candidate carousel logic (unchanged)
(function() {
    const candidates = <?= json_encode(array_map(function($c) {
        return [
            'name' => $c['name'],
            'position' => $c['position_title'],
            'photo' => !empty($c['photo']) ? $c['photo'] : 'assets/default-avatar.png',
        ];
    }, $candidates)) ?>;

    const viewport = document.getElementById('footerCarousel');
    const track = document.getElementById('footerTrack');
    if (!viewport || !track || candidates.length === 0) return;

    const renderList = [...candidates, ...candidates, ...candidates];

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    track.innerHTML = renderList.map(c => `
        <div class="fc-card">
            <img src="${escapeHtml(c.photo)}" alt="${escapeHtml(c.name)}">
            <div class="fc-name">${escapeHtml(c.name)}</div>
            <div class="fc-position">${escapeHtml(c.position)}</div>
        </div>
    `).join('');

    const cards = Array.from(track.children);
    const setLength = candidates.length;
    let cardWidth = 0;
    let trackPosition = 0;
    let paused = false;
    let lastTime = null;
    const BASE_SPEED = 40;
    const SPEED_VARIANCE = 0.85;
    const MIN_SCALE = 0.7;
    const MAX_SCALE = 1.1;

    function measure() {
        const visible = window.innerWidth <= 768 ? 3 : 5;
        cardWidth = viewport.clientWidth / visible;
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