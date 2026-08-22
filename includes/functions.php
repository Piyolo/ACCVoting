<?php
/**
 * Reusable helper functions.
 */

// The school's local timezone. Election start/end dates are stored as the
// literal wall-clock value the admin typed in (no timezone attached), so
// anything in PHP that computes "now" to compare against them — vote.php's
// open/closed check, the student dashboard's countdown — needs to run in
// this same timezone or the comparison silently drifts by the UTC offset.
// (syncElectionStatuses() below now does its own timezone conversion
// directly in SQL via `NOW() AT TIME ZONE 'Asia/Manila'`, so it doesn't
// depend on this setting — but every OTHER PHP-side "what time is it right
// now" calculation in the app still does.) Change this if the school is
// ever outside the Philippines.
date_default_timezone_set('Asia/Manila');

/**
 * Starts a session with sane, hardened cookie settings.
 * Safe to call multiple times.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Redirects to the login page unless the user is authenticated.
 * Call at the top of any protected page.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Escapes a string for safe HTML output.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Generates (or reuses) a CSRF token stored in the session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token against the session token.
 */
function verifyCsrfToken(?string $submitted): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submitted)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Sends a JSON response and terminates the script.
 */
function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/**
 * Require admin login. Redirect to admin login page if not authenticated.
 */
function requireAdminLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');   // Relative – works from /admin/
        exit;
    }
}

/**
 * Simple DB-backed rate limiter for login/registration endpoints. IP-based,
 * so it isn't bulletproof (shared NAT/mobile carriers share an IP, and a
 * determined attacker can rotate IPs), but it raises the bar a lot against
 * scripted brute-forcing or account-enumeration attempts at essentially
 * zero cost to legitimate users, who almost never hit these limits.
 *
 * $bucket separates independent limits per endpoint (e.g. 'student_login',
 * 'admin_login', 'register') so hammering one doesn't lock out another.
 */
function isRateLimited(PDO $pdo, string $bucket, int $maxAttempts = 8, int $windowSeconds = 300): bool
{
    $identifier = $bucket . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // Housekeeping: drop attempts old enough that they can never matter
    // again, so this table doesn't grow forever.
    $pdo->prepare("DELETE FROM login_attempts WHERE identifier = :id AND attempted_at < NOW() - INTERVAL '1 hour'")
        ->execute(['id' => $identifier]);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE identifier = :id AND attempted_at > NOW() - (:window * INTERVAL '1 second')
    ");
    $stmt->execute(['id' => $identifier, 'window' => $windowSeconds]);

    return (int) $stmt->fetchColumn() >= $maxAttempts;
}

/**
 * Records one attempt against a rate-limit bucket (see isRateLimited()).
 * Call this once per real login/registration POST, regardless of whether
 * it ultimately succeeds or fails.
 */
function recordAttempt(PDO $pdo, string $bucket): void
{
    $identifier = $bucket . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (:id)')->execute(['id' => $identifier]);
}

/**
 * Auto-transitions election statuses based on the current time, so the
 * schedule the admin saves is actually what runs the election — no one
 * has to remember to click Start or End on the right day.
 *
 *   scheduled -> ongoing  once start_date has arrived (and we're still
 *                before end_date)
 *   scheduled/
 *   ongoing   -> closed   once end_date has passed
 *
 * "draft" elections are deliberately EXCLUDED here. A brand-new election's
 * start/end dates are just wizard defaults (start = "now") until the admin
 * has actually reviewed and saved a schedule for it — auto-starting a
 * draft the instant it's created (because its placeholder start date is
 * technically already in the past) would launch an election nobody meant
 * to run yet. Saving a schedule is what promotes draft -> scheduled (see
 * admin/api/elections.php), and only from there do these automatic
 * start/end transitions apply.
 *
 * "paused" and "archived" are also never touched here: pausing is a
 * manual override the admin has to lift themselves (by hitting Resume,
 * which is the same as Start), and archiving is a manual admin action.
 *
 * TIMEZONE NOTE: start_date/end_date are stored as the literal wall-clock
 * value the admin typed into the schedule picker (Philippines time),
 * with no timezone attached — same as activity_logs treats CURRENT_
 * TIMESTAMP as UTC on the frontend (see dashboard2.php's
 * parseServerTimestamp()). Postgres's own NOW() returns the current
 * instant in UTC on this host, so comparing it directly against a
 * Philippines-local literal would be off by the UTC+8 offset — an
 * election scheduled to start in 1 minute wouldn't actually flip to
 * "ongoing" until Postgres's UTC clock caught up to that same literal
 * number, ~8 hours late. `NOW() AT TIME ZONE 'Asia/Manila'` converts
 * Postgres's real UTC instant into that same instant's Philippines
 * wall-clock representation, so it's finally comparable apples-to-apples
 * against the literal start_date/end_date values already in the table.
 *
 * Call this once near the top of any page/endpoint that reads elections.
 */
function syncElectionStatuses(PDO $pdo): void
{
    $pdo->exec("
        UPDATE elections SET status = 'ongoing'
        WHERE status = 'scheduled'
          AND start_date <= (NOW() AT TIME ZONE 'Asia/Manila')
          AND end_date > (NOW() AT TIME ZONE 'Asia/Manila')
    ");
    $pdo->exec("
        UPDATE elections SET status = 'closed'
        WHERE status IN ('scheduled', 'ongoing')
          AND end_date <= (NOW() AT TIME ZONE 'Asia/Manila')
    ");
}

/**
 * ================================================================
 * ELECTION LOGOS (SSG site-wide logo + per-department DSG logos)
 * ================================================================
 * Same base64-in-DB storage pattern already used for candidate photos:
 * whatever the browser uploads is stored directly as a data: URL, so it
 * survives redeploys on hosts (like Render) that wipe local disk.
 */

/**
 * Reads a single value from the generic site_settings key/value table.
 * Returns null if the key has never been set (or was explicitly cleared).
 */
function getSiteSetting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $value = $stmt->fetchColumn();
    return ($value !== false && $value !== null && $value !== '') ? $value : null;
}

/**
 * Returns [department_code => logo-data-url-or-null] for every department,
 * for bulk-rendering a page full of election cards without a query per card.
 */
function getDepartmentLogos(PDO $pdo): array
{
    $rows = $pdo->query('SELECT code, logo FROM departments')->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[$row['code']] = !empty($row['logo']) ? $row['logo'] : null;
    }
    return $map;
}

/**
 * A plain, generic "institution" glyph — shown whenever an election's
 * type (SSG) or department (DSG) doesn't have a logo uploaded yet, so an
 * election is never left with a broken image or a placeholder emoji.
 * Inline SVG (not a file) so it always matches the current theme color
 * via currentColor, in both light and dark mode.
 */
function defaultElectionLogoSvg(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" class="elogo-placeholder">'
        . '<path d="M3 21h18"/><path d="M5 21V9.5L12 4l7 5.5V21"/><path d="M9 21v-6h6v6"/>'
        . '</svg>';
}

/**
 * Renders the logo/icon markup for one election: its SSG logo, its
 * department's DSG logo, or the generic placeholder glyph if neither has
 * been uploaded. Returns ready-to-echo HTML (the <img> src is already
 * escaped) — safe to drop straight into a template.
 *
 * $ssgLogo:         from getSiteSetting($pdo, 'ssg_logo')
 * $departmentLogos: from getDepartmentLogos($pdo)
 */
function electionLogoHtml(string $type, ?string $departmentCode, ?string $ssgLogo, array $departmentLogos): string
{
    $logo = $type === 'SSG' ? $ssgLogo : ($departmentLogos[$departmentCode ?? ''] ?? null);
    if ($logo) {
        return '<img src="' . h($logo) . '" alt="" class="elogo-img">';
    }
    return defaultElectionLogoSvg();
}