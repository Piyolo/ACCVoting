<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();

$pdo = getDbConnection();
syncElectionStatuses($pdo);
$isLoggedIn = !empty($_SESSION['user_id']);

// Election logos — SSG's site-wide logo, and each department's DSG logo.
// electionLogoHtml() (includes/functions.php) falls back to a generic
// placeholder glyph if a given type/department has no logo uploaded yet.
$ssgLogo = getSiteSetting($pdo, 'ssg_logo');
$departmentLogos = getDepartmentLogos($pdo);

// Current elections (non-draft)
$elections = $pdo->query("
    SELECT name, type, department, status, start_date, end_date
    FROM elections
    WHERE status != 'draft'
    ORDER BY start_date ASC
")->fetchAll();

// Candidates for the marquee (limit to 16)
$candStmt = $pdo->prepare('
    SELECT c.id, c.name, c.photo, p.title AS position_title, e.name AS election_name
    FROM candidates c
    JOIN positions p ON p.id = c.position_id
    JOIN election_positions ep ON ep.position_id = p.id
    JOIN elections e ON e.id = ep.election_id
    WHERE e.status != \'draft\'
    ORDER BY RANDOM()
    LIMIT 16
');
$candStmt->execute();
$candidates = $candStmt->fetchAll();

// Ticker stats — real numbers, not placeholders
$totalStudents = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalVoters   = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM votes')->fetchColumn();
$liveCount = 0;
foreach ($elections as $e) { if ($e['status'] === 'ongoing') $liveCount++; }

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* =====================================================================
   TOKENS — a civic "ballot" identity: navy = institutional authority,
   gold = ceremonial seal/ribbon, lime = the act of choosing. Paper is a
   warm off-white (ballot stock), not stark white.
   ===================================================================== */
:root{
    --navy-950:#081428;
    --navy-900:#0b1f3a;
    --navy-800:#12294a;
    --navy-700:#1c3a63;
    --lime:#84cc16;
    --lime-light:#c8f26a;
    --gold:#d4af37;
    --gold-light:#f0dca0;
    --paper:#f7f5ef;
    --paper-dim:#efece3;
    --ink:#0f172a;
    --muted:#5c6b81;
    --display: 'Sora', system-ui, sans-serif;
    --body: 'Inter', system-ui, -apple-system, sans-serif;
    --mono: 'IBM Plex Mono', ui-monospace, monospace;
}
*{box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
    margin:0;font-family:var(--body);background:var(--paper);color:var(--ink);
    -webkit-font-smoothing:antialiased;
}
img{max-width:100%;display:block;}
a{color:inherit;}
.wrap{max-width:1180px;margin:0 auto;padding:0 2rem;}
@media (max-width:640px){ .wrap{padding:0 1.25rem;} }

/* Reveal-on-scroll utility */
.reveal{opacity:0;transform:translateY(24px);transition:opacity .7s ease,transform .7s ease;}
.reveal.in-view{opacity:1;transform:translateY(0);}
@media (prefers-reduced-motion:reduce){
    .reveal{opacity:1;transform:none;transition:none;}
}

/* =====================================================================
   NAV
   ===================================================================== */
.nav{
    position:sticky;top:0;z-index:200;
    background:rgba(11,31,58,0.82);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
    border-bottom:1px solid rgba(212,175,55,0.25);
}
.nav-inner{display:flex;align-items:center;justify-content:space-between;padding:.9rem 0;}
.brand{display:flex;align-items:center;gap:.75rem;text-decoration:none;}
.brand img{height:38px;width:38px;border-radius:50%;background:#fff;}
.brand .name{color:#fff;font-weight:700;font-size:.98rem;line-height:1.25;}
.brand .name small{display:block;color:var(--gold-light);font-weight:500;font-size:.68rem;letter-spacing:.08em;text-transform:uppercase;}
.nav-links{display:flex;align-items:center;gap:.4rem;}
.nav-links a{
    padding:.5rem .95rem;border-radius:999px;font-weight:600;font-size:.85rem;text-decoration:none;
    color:#dbe4f2;transition:background .15s,color .15s;
}
.nav-links a:hover{background:rgba(255,255,255,0.08);color:#fff;}
.nav-links a.pill{border:1.5px solid rgba(212,175,55,0.55);color:#fff;}
.nav-links a.pill:hover{background:rgba(212,175,55,0.12);}
.nav-links a.solid{background:var(--lime);color:#052e16;}
.nav-links a.solid:hover{background:var(--lime-light);}
.nav-toggle{display:none;background:none;border:none;color:#fff;font-size:1.6rem;cursor:pointer;}
@media (max-width:760px){
    .nav-links{position:fixed;top:64px;left:0;right:0;background:var(--navy-900);flex-direction:column;align-items:stretch;padding:1rem 1.25rem;gap:.5rem;transform:translateY(-130%);transition:transform .25s ease;border-bottom:1px solid rgba(212,175,55,.25);}
    .nav-links.open{transform:translateY(0);}
    .nav-links a{text-align:center;}
    .nav-toggle{display:block;}
}

/* =====================================================================
   HERO
   ===================================================================== */
.hero{
    position:relative;overflow:hidden;
    background:radial-gradient(120% 100% at 15% -10%, #16345c 0%, var(--navy-900) 45%, var(--navy-950) 100%);
    min-height:100vh;display:flex;flex-direction:column;justify-content:center;
    padding:7rem 0 4rem;
}
.hero-grid{
    display:grid;grid-template-columns:1.15fr .85fr;gap:3rem;align-items:center;position:relative;z-index:3;
}
@media (max-width:920px){ .hero-grid{grid-template-columns:1fr;gap:3rem;} }

.eyebrow{
    display:inline-flex;align-items:center;gap:.5rem;
    font-family:var(--mono);font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;
    color:var(--gold-light);border:1px solid rgba(212,175,55,.4);border-radius:999px;
    padding:.4rem .9rem;margin-bottom:1.5rem;
}
.eyebrow::before{content:'●';color:var(--lime);font-size:.6rem;}

.hero h1{
    font-family:var(--display);font-weight:800;color:#fff;margin:0;
    font-size:clamp(2.5rem,5.2vw,4.4rem);line-height:1.04;letter-spacing:-.02em;
}
.hero h1 .line2{color:var(--lime);display:block;}
/* The "o" in Voice/Choice, redrawn as an unvoted / voted ballot bubble.
   Sized in em so it scales with the responsive headline; currentColor
   means it automatically matches white on line 1 and lime on line 2. */
.hero h1 .ballot-o{
    display:inline-block;width:.6em;height:.6em;border-radius:50%;
    vertical-align:-.05em;box-sizing:border-box;
}
.hero h1 .ballot-o.empty{border:.1em solid currentColor;}
.hero h1 .ballot-o.filled{background:currentColor;}
.hero p.sub{
    color:#c3ceE0;font-size:1.08rem;line-height:1.65;max-width:520px;margin:1.5rem 0 2.25rem;
}
.hero-ctas{display:flex;gap:1rem;flex-wrap:wrap;}
.btn{
    display:inline-flex !important;align-items:center;justify-content:center;gap:.5rem;
    height:56px !important;min-height:56px !important;max-height:56px !important;line-height:1 !important;
    padding:0 2rem !important;border-radius:999px;font-weight:700;font-size:.95rem;
    text-decoration:none;border:2px solid transparent;cursor:pointer;white-space:nowrap;
    transition:transform .15s,background .2s,border-color .2s,box-shadow .2s;
}
.btn:active{transform:scale(.97);}
.btn-primary{background:var(--lime);color:#052e16;border-color:var(--lime);animation:pulseGlow 2.2s ease-in-out infinite;}
.btn-primary:hover{background:var(--lime-light);border-color:var(--lime-light);animation:none;}
@keyframes pulseGlow{
    0%{box-shadow:0 0 0 0 rgba(132,204,22,.5);}
    70%{box-shadow:0 0 0 14px rgba(132,204,22,0);}
    100%{box-shadow:0 0 0 0 rgba(132,204,22,0);}
}
.btn-outline{background:#fff;color:var(--navy-900);border-color:rgba(255,255,255,.7);}
.btn-outline:hover{background:var(--gold-light);border-color:var(--gold-light);}

/* --- Signature element: the ballot stub --- */
.ballot-stub{
    position:relative;background:var(--paper);border-radius:14px;
    box-shadow:0 30px 60px rgba(2,10,26,.45);padding:1.6rem 1.6rem 1.8rem;
    transform:rotate(2.5deg);
}
.ballot-stub::before{
    content:'';position:absolute;top:0;bottom:0;left:-11px;width:22px;
    background:radial-gradient(circle,var(--navy-950) 9px,transparent 9.5px);
    background-size:22px 26px;background-repeat:repeat-y;
}
.ballot-stub .bs-head{display:flex;justify-content:space-between;align-items:center;border-bottom:2px dashed var(--paper-dim);padding-bottom:.85rem;margin-bottom:1rem;}
.ballot-stub .bs-head .seal{
    width:40px;height:40px;border-radius:50%;border:2px solid var(--gold);
    display:flex;align-items:center;justify-content:center;font-family:var(--display);
    font-weight:800;color:var(--gold);font-size:1rem;flex-shrink:0;
}
.ballot-stub .bs-head .bs-title{font-family:var(--mono);font-size:.7rem;letter-spacing:.08em;color:var(--muted);text-transform:uppercase;text-align:right;}
.ballot-stub .bs-title strong{display:block;color:var(--ink);font-size:.85rem;letter-spacing:0;}
.ballot-row{display:flex;align-items:center;gap:.85rem;padding:.65rem 0;border-bottom:1px solid var(--paper-dim);}
.ballot-row:last-of-type{border-bottom:none;}
.ballot-row .bubble{
    width:24px;height:24px;border-radius:50%;border:2px solid var(--navy-700);flex-shrink:0;
    display:flex;align-items:center;justify-content:center;background:transparent;
}
.ballot-row .bubble .fill{
    width:0;height:0;border-radius:50%;background:#1a1a1a;
    transition:width .4s cubic-bezier(.34,1.2,.64,1),height .4s cubic-bezier(.34,1.2,.64,1);
}
.ballot-row.marked .bubble{border-color:#1a1a1a;}
.ballot-row.marked .bubble .fill{width:100%;height:100%;}
.ballot-row .name{font-weight:700;font-size:.92rem;color:var(--ink);transition:color .3s;}
.ballot-row.marked .name{color:var(--navy-900);}
.ballot-row .role{font-size:.72rem;color:var(--muted);}
.bs-foot{margin-top:1.1rem;display:flex;justify-content:space-between;align-items:center;font-family:var(--mono);font-size:.68rem;color:var(--muted);letter-spacing:.04em;}
.bs-foot .stamp{color:var(--gold);border:1px solid var(--gold);padding:.2rem .55rem;border-radius:5px;transform:rotate(-4deg);font-weight:600;}

/* =====================================================================
   TICKER — real live numbers, styled like an election-night results tape
   ===================================================================== */
.ticker-wrap{background:var(--navy-950);border-top:1px solid rgba(212,175,55,.25);border-bottom:1px solid rgba(212,175,55,.25);overflow:hidden;position:relative;z-index:3;}
.ticker-track{display:flex;width:max-content;animation:tickerScroll 34s linear infinite;}
.ticker-track:hover{animation-play-state:paused;}
@keyframes tickerScroll{ from{transform:translateX(0);} to{transform:translateX(-50%);} }
.ticker-item{
    display:flex;align-items:center;gap:.6rem;padding:.85rem 2.25rem;
    font-family:var(--mono);font-size:.82rem;color:#cbd5e1;white-space:nowrap;
    border-right:1px solid rgba(255,255,255,.08);
}
.ticker-item b{color:var(--lime-light);font-weight:600;}
.ticker-item .dot{color:var(--gold);}
@media (prefers-reduced-motion:reduce){ .ticker-track{animation:none;} }

/* =====================================================================
   SECTION HEADERS (shared)
   ===================================================================== */
.sec{min-height:100vh;display:flex;flex-direction:column;justify-content:center;padding:5rem 0;}
.sec-head{max-width:640px;margin-bottom:3rem;}
.sec-eyebrow{font-family:var(--mono);font-size:.72rem;letter-spacing:.1em;text-transform:uppercase;color:var(--gold);font-weight:600;margin-bottom:.6rem;}
.sec-head h2{font-family:var(--display);font-weight:800;font-size:clamp(2.1rem,3.6vw,2.8rem);margin:0 0 .75rem;color:var(--navy-900);line-height:1.05;}
.sec-head p{color:var(--muted);font-size:1.02rem;line-height:1.6;margin:0;}

/* =====================================================================
   ELECTIONS — ballot-stub cards
   ===================================================================== */
.elections{background:var(--paper);}
.elec-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;}
@media (max-width:920px){ .elec-grid{grid-template-columns:repeat(2,1fr);} }
@media (max-width:600px){ .elec-grid{grid-template-columns:1fr;} }
.elec-card{
    position:relative;background:#fff;border-radius:16px;padding:1.6rem 1.6rem 1.4rem;
    box-shadow:0 2px 10px rgba(15,23,42,.06);border-top:4px solid var(--lime);
    transition:transform .2s,box-shadow .2s;
}
.elec-card:hover{transform:translateY(-4px);box-shadow:0 16px 32px rgba(15,23,42,.1);}
.elec-header-row{display:flex;align-items:flex-start;gap:.85rem;margin-bottom:.9rem;}
.elec-logo{
    width:44px;height:44px;border-radius:50%;background:var(--paper-dim);overflow:hidden;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 1px 3px rgba(15,23,42,.08);
}
.elec-logo img{width:100%;height:100%;object-fit:cover;background:#fff;}
.elec-logo svg{width:55%;height:55%;color:var(--muted);}
.elec-heading{flex:1;min-width:0;}
.elec-heading h3{
    margin:0 0 .2rem;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;
    overflow:hidden;text-overflow:ellipsis;line-height:1.25;
}
.elec-status{
    display:inline-flex;align-items:center;gap:.4rem;font-family:var(--mono);
    font-size:.68rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    padding:.32rem .75rem;border-radius:999px;flex-shrink:0;margin-top:.1rem;
}
.elec-status .d{width:6px;height:6px;border-radius:50%;}
.elec-status.ongoing{background:rgba(34,197,94,.12);color:#15803d;} .elec-status.ongoing .d{background:#22c55e;}
.elec-status.scheduled{background:rgba(99,102,241,.12);color:#3730a3;} .elec-status.scheduled .d{background:#6366f1;}
.elec-status.paused{background:rgba(245,158,11,.15);color:#92400e;} .elec-status.paused .d{background:#f59e0b;}
.elec-status.closed{background:rgba(239,68,68,.1);color:#b91c1c;} .elec-status.closed .d{background:#ef4444;}
.elec-card h3{font-family:var(--display);font-weight:700;font-size:1.35rem;margin:0 0 .3rem;color:var(--ink);}
.elec-card .meta{color:var(--muted);font-size:.85rem;}
.elec-card .dates{font-family:var(--mono);font-size:.72rem;color:#94a3b8;margin-top:.75rem;padding-top:.75rem;border-top:1px dashed var(--paper-dim);}
.elec-empty{background:#fff;border-radius:16px;padding:3rem 2rem;text-align:center;color:var(--muted);border:1.5px dashed var(--paper-dim);}

/* =====================================================================
   HOW IT WORKS — roman-numeral ballot instructions
   ===================================================================== */
.how{background:var(--navy-900);}
.how .sec-head h2{color:#fff;}
.how .sec-head p{color:#aab6c8;}
.how .sec-eyebrow{color:var(--lime-light);}
.how-flow{display:flex;flex-direction:column;align-items:stretch;gap:0;}
.how-row{display:flex;align-items:stretch;gap:1rem;}
@media (max-width:900px){ .how-row{flex-direction:column;gap:.75rem;} }
.how-card{flex:1;min-width:0;background:rgba(255,255,255,.04);border:1px solid rgba(212,175,55,.22);border-radius:16px;padding:1.6rem 1.5rem;}
.how-card .num{font-family:var(--display);font-weight:800;font-size:2.25rem;color:var(--gold);line-height:1;margin-bottom:.85rem;}
.how-card h3{font-family:var(--body);color:#fff;font-size:1.05rem;font-weight:700;margin:0 0 .4rem;}
.how-card p{color:#aab6c8;font-size:.88rem;line-height:1.55;margin:0;}
.how-arrow-badge{
    flex-shrink:0;width:34px;height:34px;border-radius:50%;
    border:1.5px solid var(--gold);background:var(--navy-900);
    display:flex;align-items:center;justify-content:center;color:var(--gold);
    align-self:center;position:relative;z-index:2;
}
.how-arrow-badge svg{width:15px;height:15px;}
.how-arrow-badge.down{transform:rotate(90deg);margin:.5rem auto;}
@media (max-width:900px){
    .how-arrow-badge:not(.down){transform:rotate(90deg);margin:.25rem auto;}
}

/* =====================================================================
   CANDIDATES — marquee of ID badges
   ===================================================================== */
.candidates{background:var(--paper-dim);overflow:hidden;}
.cand-marquee-mask{
    -webkit-mask-image:linear-gradient(to right, transparent 0, black 6%, black 94%, transparent 100%);
    mask-image:linear-gradient(to right, transparent 0, black 6%, black 94%, transparent 100%);
}
.cand-track{display:flex;width:max-content;gap:1.1rem;animation:candScroll 42s linear infinite;}
.cand-track:hover{animation-play-state:paused;}
@keyframes candScroll{ from{transform:translateX(0);} to{transform:translateX(-50%);} }
.cand-badge{
    flex:0 0 auto;width:168px;background:#fff;border-radius:14px;padding:1.1rem .9rem 1rem;
    text-align:center;box-shadow:0 2px 10px rgba(15,23,42,.07);position:relative;
}
.cand-badge .tab{position:absolute;top:-7px;left:50%;transform:translateX(-50%);width:34px;height:14px;background:var(--gold);border-radius:0 0 6px 6px;}
.cand-badge img{
    width:64px;height:64px;border-radius:50%;object-fit:cover;background:var(--paper-dim);
    margin:.5rem auto .6rem;box-shadow:0 0 0 3px #fff,0 0 0 4px var(--lime);
}
.cand-badge .cb-name{font-weight:700;font-size:.82rem;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cand-badge .cb-role{font-family:var(--mono);font-size:.66rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:.15rem;}
.cand-badge .cb-election{
    margin-top:.65rem;padding-top:.55rem;border-top:1px dashed var(--paper-dim);
    font-size:.66rem;font-weight:600;color:var(--navy-900);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}

/* =====================================================================
   FINAL CTA
   ===================================================================== */
.final-cta{
    background:linear-gradient(135deg,var(--navy-950) 0%,var(--navy-800) 100%);
    min-height:100vh;display:flex;flex-direction:column;justify-content:center;
    padding:5rem 0;text-align:center;position:relative;overflow:hidden;
}
.final-cta::before{
    content:'✓';position:absolute;font-family:var(--display);font-size:26rem;font-weight:800;
    color:rgba(212,175,55,.05);top:-6rem;right:-3rem;transform:rotate(12deg);
}
.final-cta h2{font-family:var(--display);font-weight:800;color:#fff;font-size:clamp(2.2rem,4.4vw,3.4rem);margin:0 0 .75rem;position:relative;}
.final-cta h2 .hi{color:var(--lime);}
.final-cta p{color:#c3ceE0;font-size:1.05rem;margin:0 0 2rem;position:relative;}
.final-cta .ctas{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;position:relative;}

/* =====================================================================
   FOOTER
   ===================================================================== */
footer{background:var(--navy-950);padding:2.5rem 0;}
.foot-inner{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1rem;}
.foot-brand{display:flex;align-items:center;gap:.75rem;}
.foot-brand img{height:34px;width:34px;border-radius:50%;background:#fff;}
.foot-brand strong{display:block;color:#fff;font-size:.9rem;}
.foot-brand small{color:#7d8ba0;font-size:.75rem;}
footer .foot-links{display:flex;gap:1.5rem;}
footer .foot-links a{color:#aab6c8;font-size:.85rem;text-decoration:none;}
footer .foot-links a:hover{color:#fff;}

/* Focus visibility (accessibility floor) */
a:focus-visible,button:focus-visible{outline:2px solid var(--lime);outline-offset:2px;}
.icon{width:17px;height:17px;flex-shrink:0;stroke:currentColor;}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
        <symbol id="icon-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 12.5l2.5 2.5L16 9.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
        <symbol id="icon-chart" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="20" x2="6" y2="12"/><line x1="12" y1="20" x2="12" y2="6"/><line x1="18" y1="20" x2="18" y2="15"/></g></symbol>
        <symbol id="icon-cap" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 9.5 12 5 2 9.5l10 4.5 10-4.5Z"/><path d="M6 11.5V16c0 1.4 2.7 2.8 6 2.8s6-1.4 6-2.8v-4.5"/></g></symbol>
        <symbol id="icon-landmark" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2.5 21 8 3 8"/><line x1="5" y1="18" x2="5" y2="10.5"/><line x1="9.5" y1="18" x2="9.5" y2="10.5"/><line x1="14.5" y1="18" x2="14.5" y2="10.5"/><line x1="19" y1="18" x2="19" y2="10.5"/><line x1="3" y1="21.5" x2="21" y2="21.5"/></g></symbol>
        <symbol id="icon-lock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/></g></symbol>
        <symbol id="icon-arrow" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="18" y2="12"/><polyline points="12 6 18 12 12 18"/></g></symbol>
    </defs>
</svg>

<nav class="nav">
    <div class="wrap nav-inner">
        <a href="#" class="brand">
            <img src="assets/logo.png" alt="ACC seal">
            <span class="name">Aklan Catholic College<small>School Elections</small></span>
        </a>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle menu">☰</button>
        <div class="nav-links" id="navLinks">
            <a href="#elections">Elections</a>
            <a href="results.php">Results</a>
            <?php if ($isLoggedIn): ?>
                <a href="dashboard.php" class="solid">Dashboard</a>
                <a href="logout.php" class="pill">Log Out</a>
            <?php else: ?>
                <a href="student/register.php" class="pill">Register</a>
                <a href="login.php" class="solid">Log In</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- ========== HERO ========== -->
<section class="hero">
    <div class="wrap hero-grid">
        <div>
            <div class="eyebrow">Official Ballot &middot; A.Y. 2025&ndash;2026</div>
            <h1 aria-label="Your Voice Your Choice">Your V<span class="ballot-o empty" aria-hidden="true"></span>ice<span class="line2">Your Ch<span class="ballot-o filled" aria-hidden="true"></span>ice</span></h1>
            <p class="sub">Participate in shaping the future of Aklan Catholic College. Cast your vote for the leaders who will represent your department and your school.</p>
            <div class="hero-ctas">
                <a href="<?= $isLoggedIn ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary"><svg class="icon"><use href="#icon-check"/></svg> Vote Now</a>
                <a href="results.php" class="btn btn-outline"><svg class="icon"><use href="#icon-chart"/></svg> View Live Results</a>
            </div>
        </div>

        <div class="ballot-stub reveal">
            <div class="bs-head">
                <div class="seal">ACC</div>
                <div class="bs-title">Sample ballot<strong>President &mdash; SSG</strong></div>
            </div>
            <div class="ballot-demo" id="ballotDemo">
                <div class="ballot-row marked">
                    <div class="bubble"><div class="fill"></div></div>
                    <div><div class="name">Maria Santos</div><div class="role">4th Year &middot; BS IT</div></div>
                </div>
                <div class="ballot-row">
                    <div class="bubble"><div class="fill"></div></div>
                    <div><div class="name">Julian Reyes</div><div class="role">3rd Year &middot; BSBA</div></div>
                </div>
                <div class="ballot-row">
                    <div class="bubble"><div class="fill"></div></div>
                    <div><div class="name">Anna Cruz</div><div class="role">4th Year &middot; AB Comm</div></div>
                </div>
            </div>
            <div class="bs-foot">
                <span>Ballot ID #<?= str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT) ?></span>
                <span class="stamp">Confidential</span>
            </div>
        </div>
    </div>
</section>

<!-- ========== TICKER ========== -->
<div class="ticker-wrap">
    <?php
        $tickerItems = [
            ['icon-landmark', $liveCount . ' election' . ($liveCount === 1 ? '' : 's') . ' live right now'],
            ['icon-cap', $totalStudents . ' students registered'],
            ['icon-check', $totalVoters . ' ballots cast so far'],
            ['icon-landmark', 'Supreme &amp; Department Student Government'],
            ['icon-lock', 'One vote per position, fully confidential'],
        ];
        $tickerHtml = '';
        foreach ($tickerItems as [$icon, $text]) {
            $tickerHtml .= '<div class="ticker-item"><svg class="icon"><use href="#' . $icon . '"/></svg>' . $text . ' <span class="dot">&middot;</span></div>';
        }
    ?>
    <div class="ticker-track"><?= $tickerHtml . $tickerHtml ?></div>
</div>

<!-- ========== CURRENT ELECTIONS ========== -->
<section class="sec elections" id="elections">
    <div class="wrap">
        <div class="sec-head reveal">
            <div class="sec-eyebrow">Happening now</div>
            <h2>Current elections</h2>
            <p>Every election open to students right now — check back once you're registered to see which ones you're eligible for.</p>
        </div>

        <?php if (empty($elections)): ?>
            <div class="elec-empty reveal">No elections are currently open. Check back soon!</div>
        <?php else: ?>
            <div class="elec-grid">
                <?php foreach ($elections as $e):
                    $now = new DateTime();
                    $start = new DateTime($e['start_date']);
                    $end = new DateTime($e['end_date']);
                    $isOpen = ($e['status'] === 'ongoing' && $now >= $start && $now <= $end);
                    $statusText = $isOpen ? 'Ongoing' : (in_array($e['status'], ['scheduled','draft']) ? 'Upcoming' : ($e['status'] === 'paused' ? 'Paused' : 'Ended'));
                    $sClass = $isOpen ? 'ongoing' : (in_array($e['status'], ['scheduled','draft']) ? 'scheduled' : ($e['status'] === 'paused' ? 'paused' : 'closed'));
                ?>
                    <div class="elec-card reveal">
                        <div class="elec-header-row">
                            <div class="elec-logo"><?= electionLogoHtml($e['type'], $e['department'], $ssgLogo, $departmentLogos) ?></div>
                            <div class="elec-heading">
                                <h3 title="<?= htmlspecialchars($e['name']) ?>"><?= htmlspecialchars($e['name']) ?></h3>
                                <div class="meta"><?= $e['type'] === 'SSG' ? 'Supreme Student Government' : 'Department Student Government' . ($e['department'] ? ' — ' . htmlspecialchars($e['department']) : '') ?></div>
                            </div>
                            <span class="elec-status <?= $sClass ?>"><span class="d"></span><?= $statusText ?></span>
                        </div>
                        <div class="dates"><?= date('M j, Y', $start->getTimestamp()) ?> &rarr; <?= date('M j, Y', $end->getTimestamp()) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ========== HOW IT WORKS ========== -->
<section class="sec how">
    <div class="wrap">
        <div class="sec-head reveal">
            <div class="sec-eyebrow">How voting works</div>
            <h2>Every step, start to finish</h2>
            <p>The complete path from an unactivated account to a confirmed ballot — nothing skipped.</p>
        </div>
        <div class="how-flow">
            <div class="how-row">
                <div class="how-card reveal">
                    <div class="num">01</div>
                    <h3>Register</h3>
                    <p>Activate your account with your Student ID and set a password — one-time only.</p>
                </div>
                <div class="how-arrow-badge" aria-hidden="true"><svg class="icon"><use href="#icon-arrow"/></svg></div>
                <div class="how-card reveal">
                    <div class="num">02</div>
                    <h3>Log in</h3>
                    <p>Sign in anytime with your Student ID and password to reach your dashboard.</p>
                </div>
                <div class="how-arrow-badge" aria-hidden="true"><svg class="icon"><use href="#icon-arrow"/></svg></div>
                <div class="how-card reveal">
                    <div class="num">03</div>
                    <h3>Choose an election</h3>
                    <p>See every election you're eligible for — school-wide or your own department.</p>
                </div>
            </div>

            <div class="how-arrow-badge down" aria-hidden="true"><svg class="icon"><use href="#icon-arrow"/></svg></div>

            <div class="how-row">
                <div class="how-card reveal">
                    <div class="num">04</div>
                    <h3>Review candidates</h3>
                    <p>Read each candidate's platform and party before deciding who represents you.</p>
                </div>
                <div class="how-arrow-badge" aria-hidden="true"><svg class="icon"><use href="#icon-arrow"/></svg></div>
                <div class="how-card reveal">
                    <div class="num">05</div>
                    <h3>Cast your ballot</h3>
                    <p>Pick one candidate per position, confirm your choices, and submit.</p>
                </div>
                <div class="how-arrow-badge" aria-hidden="true"><svg class="icon"><use href="#icon-arrow"/></svg></div>
                <div class="how-card reveal">
                    <div class="num">06</div>
                    <h3>Get confirmation</h3>
                    <p>Your vote is recorded instantly and confidentially — one ballot per election, guaranteed.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ========== CANDIDATES MARQUEE ========== -->
<?php if (!empty($candidates)): ?>
<section class="sec candidates">
    <div class="wrap">
        <div class="sec-head reveal">
            <div class="sec-eyebrow">On the ballot</div>
            <h2>Meet the candidates</h2>
            <p>A rotating look at students running across every open election.</p>
        </div>
    </div>
    <div class="cand-marquee-mask">
        <?php
            $badgeHtml = '';
            foreach ($candidates as $c) {
                $photo = !empty($c['photo']) ? $c['photo'] : 'assets/default-avatar.png';
                $badgeHtml .= '<div class="cand-badge"><div class="tab"></div>'
                    . '<img src="' . htmlspecialchars($photo) . '" alt="">'
                    . '<div class="cb-name">' . htmlspecialchars($c['name']) . '</div>'
                    . '<div class="cb-role">' . htmlspecialchars($c['position_title']) . '</div>'
                    . '<div class="cb-election">' . htmlspecialchars($c['election_name']) . '</div></div>';
            }
        ?>
        <div class="cand-track"><?= $badgeHtml . $badgeHtml ?></div>
    </div>
</section>
<?php endif; ?>

<!-- ========== FINAL CTA ========== -->
<section class="final-cta">
    <div class="wrap">
        <h2>Every ballot <span class="hi">counts</span></h2>
        <p>Make sure your voice is heard — it takes less than two minutes.</p>
        <div class="ctas">
            <a href="<?= $isLoggedIn ? 'dashboard.php' : 'login.php' ?>" class="btn btn-primary">Log In to Vote</a>
            <?php if (!$isLoggedIn): ?>
                <a href="student/register.php" class="btn btn-outline">Register Now</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<footer>
    <div class="wrap foot-inner">
        <div class="foot-brand">
            <img src="assets/logo.png" alt="ACC seal">
            <div><strong>Aklan Catholic College</strong><small>Student Elections &middot; &copy; <?= date('Y') ?> School Elections, ACC</small></div>
        </div>
        <div class="foot-links">
            <a href="results.php">Results</a>
            <a href="login.php">Log In</a>
            <a href="student/register.php">Register</a>
        </div>
    </div>
</footer>

<script>
// Mobile nav toggle
const navToggle = document.getElementById('navToggle');
const navLinks = document.getElementById('navLinks');
navToggle?.addEventListener('click', () => navLinks.classList.toggle('open'));

// Scroll-reveal
const revealEls = document.querySelectorAll('.reveal');
if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });
    revealEls.forEach(el => io.observe(el));
} else {
    revealEls.forEach(el => el.classList.add('in-view'));
}

// Sample ballot: loop the vote between candidates so the bubble-shading
// animation is visible on load, not just on interaction.
(function() {
    const demo = document.getElementById('ballotDemo');
    if (!demo) return;
    const rows = demo.querySelectorAll('.ballot-row');
    if (rows.length < 2) return;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion) return;

    let current = 0;
    setInterval(() => {
        rows[current].classList.remove('marked');
        current = (current + 1) % rows.length;
        rows[current].classList.add('marked');
    }, 2400);
})();
</script>
</body>
</html>