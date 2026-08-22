<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

startSecureSession();

// If already logged in, skip straight to the dashboard.
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$justRegistered = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $pdo = getDbConnection();

        if (isRateLimited($pdo, 'student_login')) {
            $error = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
        $studentId = trim($_POST['student_id'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($studentId === '' || $password === '') {
            $error = 'Please enter both ID and Password.';
        } else {
            recordAttempt($pdo, 'student_login');
            $stmt = $pdo->prepare('
            SELECT id, name, password, has_voted, registration_status
            FROM users
            WHERE student_id = :student_id
        ');
            $stmt->execute(['student_id' => $studentId]);
            $user = $stmt->fetch();

            if ($user) {
                if ($user['registration_status'] === 'unregistered') {
                    $error = 'This account needs to be activated. Please <a href="student/register.php">register first</a>.';
                } elseif ($user['registration_status'] === 'suspended') {
                    $error = 'This account has been suspended. Please contact the election administrator.';
                } elseif (password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];

                    // Always land on the dashboard, never straight to
                    // results.php. has_voted is a legacy "voted in at least
                    // one election, ever" flag — with multiple elections
                    // possibly running at once, a student who's voted in one
                    // may still have another to vote in, and the dashboard
                    // (which shows real per-election status, plus its own
                    // Results tab) is the right landing spot either way.
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid ID or Password.';
                }
            } else {
                $error = 'Invalid ID or Password.';
            }
        }
        }
    }
}

startSecureSession();
$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>Login | School Elections</title>
<link rel="stylesheet" href="assets/style.css">
<style>
    * { -webkit-tap-highlight-color: transparent; }

    html, body {
        margin: 0;
        min-height: 100vh;
        min-height: 100dvh;
        overscroll-behavior-y: none;
    }

    /* ----- Background: slow ambient zoom/pan, no jank on low-end phones ----- */
    .bg-photo {
        position: fixed;
        inset: 0;
        z-index: -2;
        background-image: url('assets/school-bg.jpg');
        background-size: cover;
        background-position: center;
        filter: blur(6px) brightness(0.85);
        transform: scale(1.12);
        animation: bgDrift 26s ease-in-out infinite alternate;
    }
    @keyframes bgDrift {
        0%   { transform: scale(1.12) translate(0, 0); }
        100% { transform: scale(1.2) translate(-1.5%, -1.5%); }
    }

    .bg-overlay {
        position: fixed;
        inset: 0;
        z-index: -1;
        background:
            radial-gradient(circle at 20% 15%, rgba(132, 204, 22, 0.16), transparent 45%),
            radial-gradient(circle at 85% 85%, rgba(59, 130, 246, 0.12), transparent 50%),
            rgba(5, 10, 20, 0.45);
    }

    @media (prefers-reduced-motion: reduce) {
        .bg-photo { animation: none; }
    }

    .page-center {
        position: relative;
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
        padding-top: max(1.25rem, env(safe-area-inset-top));
        padding-bottom: max(1.25rem, env(safe-area-inset-bottom));
        box-sizing: border-box;
    }

    /* ----- Card ----- */
    .page-center .card {
        position: relative;
        background: rgba(255, 255, 255, 0.14);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.25);
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.4);
        border-radius: 20px;
        padding: 2rem 1.75rem 2rem;
        width: 100%;
        max-width: 380px;
        box-sizing: border-box;
        opacity: 0;
        transform: translateY(18px) scale(0.98);
        animation: cardIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.05s forwards;
    }
    @keyframes cardIn {
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .page-center .tagline,
    .page-center .card-title,
    .page-center label {
        color: #ffffff;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
    }

    .page-center .tagline {
        background: none;
        box-shadow: none;
        padding: 0;
        margin: 0 0 0.5rem;
    }

    .card-title {
        text-align: center;
        letter-spacing: 0.01em;
    }

    .subtitle {
        text-align: center;
        color: rgba(255, 255, 255, 0.75);
        font-size: 0.85rem;
        margin: -0.6rem 0 1.25rem;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
    }

    .page-center label {
        font-size: 0.92rem;
        font-weight: 600;
        display: block;
        margin-top: 1rem;
        margin-bottom: 0.4rem;
        letter-spacing: 0.01em;
    }

    /* Fields fade/slide in staggered, after the card lands */
    .form-field {
        opacity: 0;
        transform: translateY(10px);
        animation: fieldIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .form-field:nth-of-type(1) { animation-delay: 0.22s; }
    .form-field:nth-of-type(2) { animation-delay: 0.30s; }
    @keyframes fieldIn {
        to { opacity: 1; transform: translateY(0); }
    }

    .page-center input[type="text"],
    .page-center input[type="password"] {
        background-color: rgba(255, 255, 255, 0.92);
        color: #1b1f24;
        caret-color: #1b1f24;
        border: 1.5px solid rgba(255, 255, 255, 0.4);
        width: 100%;
        padding: 0.85rem 0.9rem;
        border-radius: 10px;
        font-size: 16px; /* keeps iOS from auto-zooming on focus */
        min-height: 48px;
        box-sizing: border-box;
        transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.12s ease;
    }

    /* Some mobile browsers apply dark-mode UA colors to autofilled/native
       form controls independently of the color-scheme meta — pin these
       explicitly so ID/Password text is always dark-on-light, never
       white-on-light or mismatched between the two fields. */
    .page-center input[type="text"]::placeholder,
    .page-center input[type="password"]::placeholder {
        color: #6b7280;
    }
    .page-center input:-webkit-autofill {
        -webkit-text-fill-color: #1b1f24;
        box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.92) inset;
    }

    .page-center input[type="text"]:focus,
    .page-center input[type="password"]:focus {
        outline: none;
        border-color: #84cc16;
        box-shadow: 0 0 0 4px rgba(132, 204, 22, 0.35);
    }

    /* Kill native reveal/clear icons so our custom eye toggle is the only one */
    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
        display: none;
        width: 0;
        height: 0;
    }
    input[type="password"]::-webkit-credentials-auto-fill-button,
    input[type="password"]::-webkit-strong-password-auto-fill-button {
        visibility: hidden;
        display: none !important;
        pointer-events: none;
        position: absolute;
        right: 0;
    }

    /* ----- Button ----- */
    .page-center .btn-primary {
        position: relative;
        overflow: hidden;
        background: #84cc16;
        border-color: #84cc16;
        color: #052e16;
        width: 100%;
        margin-top: 1.6rem;
        min-height: 50px;
        font-size: 1rem;
        letter-spacing: 0.01em;
        transition: background 0.2s ease, border-color 0.2s ease, transform 0.12s ease, box-shadow 0.2s ease;
        opacity: 0;
        transform: translateY(10px);
        animation: fieldIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.38s forwards;
        box-shadow: 0 8px 20px rgba(132, 204, 22, 0.25);
    }
    .page-center .btn-primary:hover { background: #65a30d; border-color: #65a30d; }
    .page-center .btn-primary:active { transform: scale(0.97); }
    .page-center .btn-primary:disabled {
        opacity: 0.85;
        cursor: default;
        transform: none;
    }

    /* Ripple on tap/click */
    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.55);
        transform: scale(0);
        animation: rippleOut 0.55s ease-out forwards;
        pointer-events: none;
    }
    @keyframes rippleOut {
        to { transform: scale(2.6); opacity: 0; }
    }

    /* Spinner shown inside the button while submitting */
    .btn-spinner {
        display: none;
        width: 18px;
        height: 18px;
        border: 2.5px solid rgba(5, 46, 22, 0.35);
        border-top-color: #052e16;
        border-radius: 50%;
        margin-right: 0.6rem;
        animation: spin 0.7s linear infinite;
    }
    .btn-primary.is-loading .btn-spinner { display: inline-block; }
    .btn-primary.is-loading .btn-label::after { content: 'Logging in…'; }
    .btn-primary.is-loading .btn-label > span { display: none; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .btn-label { display: inline-flex; align-items: center; justify-content: center; }

    .page-center a { color: #bef264; }
    .page-center a:hover { color: #d9f99d; }

    .page-center .logo {
        width: 96px;
        height: 96px;
        object-fit: contain;
        animation: logoPop 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.05s both;
    }
    @keyframes logoPop {
        0% { opacity: 0; transform: scale(0.6) rotate(-8deg); }
        100% { opacity: 1; transform: scale(1) rotate(0deg); }
    }

    .logo-area { text-align: center; }

    /* ----- Alerts: slide + shake on error ----- */
    .alert {
        border-radius: 10px;
        animation: alertIn 0.35s ease both;
    }
    .alert-error { animation: alertIn 0.35s ease both, shake 0.45s ease 0.35s; }
    @keyframes alertIn {
        from { opacity: 0; transform: translateY(-6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes shake {
        10%, 90% { transform: translateX(-1px); }
        20%, 80% { transform: translateX(2px); }
        30%, 50%, 70% { transform: translateX(-4px); }
        40%, 60% { transform: translateX(4px); }
    }
    .card.has-error {
        animation: cardIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.05s forwards, shake 0.5s ease 0.5s;
    }

    /* Show/hide password toggle */
    .password-field {
        position: relative;
        display: flex;
        align-items: stretch;
    }

    .password-field input {
        flex: 1;
        padding-right: 2.6rem;
    }

    .toggle-password-btn {
        position: absolute;
        right: 0.3rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        padding: 0.5rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 0;
        min-width: 44px;
        min-height: 44px;
        border-radius: 8px;
        transition: background 0.15s ease;
    }
    .toggle-password-btn:active { background: rgba(0, 0, 0, 0.06); }

    .toggle-password-btn svg {
        width: 20px;
        height: 20px;
        stroke: #333333;
        transition: transform 0.15s ease;
    }

    .toggle-password-btn .icon-eye { display: none; }
    .toggle-password-btn .icon-eye-off { display: block; }
    .toggle-password-btn.is-visible .icon-eye { display: block; }
    .toggle-password-btn.is-visible .icon-eye-off { display: none; }
    .toggle-password-btn:active svg { transform: scale(0.88); }

    .register-cta {
        margin-top: 1.5rem;
        text-align: center;
        font-size: 0.88rem;
        color: rgba(255, 255, 255, 0.9);
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        opacity: 0;
        animation: fieldIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.46s forwards;
    }
    .register-cta .btn {
        display: inline-block;
        margin-top: 0.7rem;
        width: 100%;
        box-sizing: border-box;
        min-height: 46px;
        line-height: 1.2;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.12);
        border: 1.5px solid rgba(255, 255, 255, 0.5);
        color: #ffffff;
        border-radius: 10px;
        padding: 0.6rem 1rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.2s ease, border-color 0.2s ease, transform 0.12s ease;
    }
    .register-cta .btn:hover { background: rgba(255, 255, 255, 0.22); border-color: #ffffff; }
    .register-cta .btn:active { transform: scale(0.97); }

    .back-home {
        margin-top: 1rem;
        text-align: center;
        font-size: 0.85rem;
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        animation: fieldIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.52s forwards;
    }
    .back-home a { padding: 0.4rem 0.6rem; }

    @media (prefers-reduced-motion: reduce) {
        * { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; }
    }

    /* Small phones */
    @media (max-width: 380px) {
        .page-center .card { padding: 1.6rem 1.25rem 1.75rem; border-radius: 16px; }
        .page-center .logo { width: 84px; height: 84px; }
    }
</style>
</head>
<body>
<div class="bg-photo"></div>
<div class="bg-overlay"></div>
<div class="page-center">
    <div class="card<?= $error ? ' has-error' : '' ?>" id="loginCard">
        <div class="logo-area">
            <img src="assets/logo.png" alt="FICT Logo" class="logo">
        </div>
        <h2 class="card-title">Welcome Back</h2>
        <p class="subtitle">Log in to cast your vote.</p>

        <?php if ($justRegistered && !$error): ?>
            <div class="alert alert-success">✅ Account activated! Please log in with your new password.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error /* may contain a safe internal <a> link, see above */ ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="form" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="form-field">
                <label for="student_id">ID</label>
                <input type="text" id="student_id" name="student_id" required autofocus autocomplete="username" inputmode="numeric">
            </div>

            <div class="form-field">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                    <button type="button" class="toggle-password-btn" id="togglePasswordBtn" aria-label="Show password" aria-pressed="false">
                        <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a20.3 20.3 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" id="submitBtn">
                <span class="btn-spinner"></span>
                <span class="btn-label"><span>Log In</span></span>
            </button>
        </form>

        <div class="register-cta">
            Not yet registered?
            <a href="student/register.php" class="btn">Register here</a>
        </div>

        <div class="back-home">
            <a href="landingpage.php">&larr; Back to Home</a>
        </div>
    </div>
</div>
<script>
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');

    if (togglePasswordBtn && passwordInput) {
        togglePasswordBtn.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            this.classList.toggle('is-visible', isHidden);
            this.setAttribute('aria-pressed', String(isHidden));
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }

    // Ripple + loading state on submit. The form still submits normally
    // (no preventDefault) — this is purely a visual affordance so a slow
    // connection doesn't look like a dead tap on mobile.
    const submitBtn = document.getElementById('submitBtn');
    const loginForm = document.getElementById('loginForm');

    function spawnRipple(btn, x, y) {
        const rect = btn.getBoundingClientRect();
        const ripple = document.createElement('span');
        const size = Math.max(rect.width, rect.height) * 1.2;
        ripple.className = 'ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (x - rect.left - size / 2) + 'px';
        ripple.style.top = (y - rect.top - size / 2) + 'px';
        btn.appendChild(ripple);
        setTimeout(() => ripple.remove(), 600);
    }

    if (submitBtn) {
        submitBtn.addEventListener('pointerdown', function (e) {
            spawnRipple(this, e.clientX, e.clientY);
        });
    }

    if (loginForm && submitBtn) {
        loginForm.addEventListener('submit', function () {
            if (!loginForm.checkValidity()) return;
            submitBtn.classList.add('is-loading');
            submitBtn.disabled = true;
        });
    }

    // Re-trigger the shake if the user submits again after seeing an error
    // (the CSS animation only plays once on page load otherwise).
    const cardEl = document.getElementById('loginCard');
    if (cardEl && cardEl.classList.contains('has-error')) {
        cardEl.addEventListener('animationend', function handler(e) {
            if (e.animationName === 'shake') {
                cardEl.removeEventListener('animationend', handler);
            }
        });
    }
</script>
</body>
</html>