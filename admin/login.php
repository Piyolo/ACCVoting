<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

startSecureSession();

// If already logged in, go to dashboard
if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired. Please try again.';
    } else {
        $pdo = getDbConnection();

        if (isRateLimited($pdo, 'admin_login')) {
            $error = 'Too many login attempts. Please wait a few minutes and try again.';
        } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            recordAttempt($pdo, 'admin_login');
            $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM admins WHERE username = :username');
            $stmt->execute(['username' => $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['is_admin'] = true;

                // Update last_login
                $update = $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = :id');
                $update->execute(['id' => $admin['id']]);

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid username or password.';
        }
        }
    }
}

$csrfToken = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
    <title>Admin Login | School Elections</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        * { -webkit-tap-highlight-color: transparent; box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(160deg, #1e293b 0%, #0f172a 55%, #0a1120 100%);
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient glow, same visual language as the admin dashboard's
           lime accent — quiet and professional, no photography needed
           for a back-office screen. */
        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            filter: blur(70px);
            pointer-events: none;
            z-index: 0;
        }
        body::before {
            width: 420px; height: 420px;
            background: rgba(132, 204, 22, 0.16);
            top: -120px; right: -100px;
        }
        body::after {
            width: 380px; height: 380px;
            background: rgba(59, 130, 246, 0.12);
            bottom: -140px; left: -100px;
        }

        .page-center {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
            padding-top: max(1.25rem, env(safe-area-inset-top));
            padding-bottom: max(1.25rem, env(safe-area-inset-bottom));
        }

        .card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
            padding: 2.25rem 2rem 2rem;
            width: 100%;
            max-width: 380px;
            text-align: left;
            opacity: 0;
            transform: translateY(16px);
            animation: cardIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) 0.05s forwards;
        }
        @keyframes cardIn { to { opacity: 1; transform: translateY(0); } }

        .admin-badge {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, #a3e635, #65a30d);
            color: #052e16;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.35rem;
            box-shadow: 0 8px 20px rgba(132, 204, 22, 0.3);
            margin: 0 auto 1.1rem;
            opacity: 0;
            transform: scale(0.7) rotate(-6deg);
            animation: badgePop 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) 0.08s forwards;
        }
        @keyframes badgePop { to { opacity: 1; transform: scale(1) rotate(0); } }

        .card-title {
            text-align: center;
            margin: 0 0 0.2rem;
            color: #0f172a;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .card-subtitle {
            text-align: center;
            margin: 0 0 1.5rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .form-field {
            opacity: 0;
            transform: translateY(8px);
            animation: fieldIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .form-field:nth-of-type(1) { animation-delay: 0.16s; }
        .form-field:nth-of-type(2) { animation-delay: 0.22s; margin-top: 1rem; }
        @keyframes fieldIn { to { opacity: 1; transform: translateY(0); } }

        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.4rem;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            background-color: #ffffff;
            color: #0f172a;
            caret-color: #0f172a;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.8rem 0.9rem;
            font-size: 16px; /* prevents iOS auto-zoom on focus */
            min-height: 48px;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #84cc16;
            box-shadow: 0 0 0 4px rgba(132, 204, 22, 0.18);
        }
        input::placeholder { color: #94a3b8; }
        input:-webkit-autofill {
            -webkit-text-fill-color: #0f172a;
            box-shadow: 0 0 0 1000px #ffffff inset;
        }

        /* Kill native reveal/clear icons so nothing doubles up visually */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none; width: 0; height: 0; }
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            visibility: hidden; display: none !important; pointer-events: none;
            position: absolute; right: 0;
        }

        .btn-primary {
            position: relative;
            overflow: hidden;
            width: 100%;
            min-height: 48px;
            margin-top: 1.5rem;
            background: #1e293b;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, transform 0.12s ease;
            opacity: 0;
            transform: translateY(8px);
            animation: fieldIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards;
        }
        .btn-primary:hover { background: #0f172a; }
        .btn-primary:active { transform: scale(0.98); }
        .btn-primary:disabled { opacity: 0.85; cursor: default; transform: none; }

        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.28);
            transform: scale(0);
            animation: rippleOut 0.55s ease-out forwards;
            pointer-events: none;
        }
        @keyframes rippleOut { to { transform: scale(2.6); opacity: 0; } }

        .btn-spinner {
            display: none;
            width: 16px; height: 16px;
            border: 2.5px solid rgba(255, 255, 255, 0.35);
            border-top-color: #fff;
            border-radius: 50%;
            margin-right: 0.6rem;
            animation: spin 0.7s linear infinite;
        }
        .btn-primary.is-loading .btn-spinner { display: inline-block; }
        .btn-primary.is-loading .btn-label > span { display: none; }
        .btn-primary.is-loading .btn-label::after { content: 'Signing in…'; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .alert {
            border-radius: 10px;
            padding: 0.7rem 0.9rem;
            font-size: 0.85rem;
            margin-bottom: 1rem;
            animation: alertIn 0.3s ease both, shake 0.4s ease 0.3s;
        }
        @keyframes alertIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-4px); }
            40%, 60% { transform: translateX(4px); }
        }
        .alert-error { background: #fee2e2; color: #991b1b; }

        .footer-links {
            margin-top: 1.4rem;
            text-align: center;
            font-size: 0.83rem;
            opacity: 0;
            animation: fieldIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0.38s forwards;
        }
        .footer-links a {
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem 0.6rem;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
        }
        .footer-links a:hover { color: #1e293b; text-decoration: underline; }

        @media (prefers-reduced-motion: reduce) {
            * { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; }
        }

        @media (max-width: 380px) {
            .card { padding: 1.75rem 1.4rem 1.6rem; border-radius: 16px; }
        }
    </style>
</head>
<body>
<div class="page-center">
    <div class="card" id="loginCard">
        <div class="admin-badge">FICT</div>
        <h2 class="card-title">Election Admin</h2>
        <p class="card-subtitle">Sign in to manage elections, students, and results.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="form" id="adminLoginForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="form-field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus autocomplete="username">
            </div>

            <div class="form-field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-primary" id="submitBtn">
                <span class="btn-spinner"></span>
                <span class="btn-label"><span>Log In</span></span>
            </button>
        </form>

        <div class="footer-links">
            <a href="../login.php">&larr; Student Login</a>
        </div>
    </div>
</div>
<script>
    const submitBtn = document.getElementById('submitBtn');
    const adminLoginForm = document.getElementById('adminLoginForm');

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

    if (adminLoginForm && submitBtn) {
        adminLoginForm.addEventListener('submit', function () {
            if (!adminLoginForm.checkValidity()) return;
            submitBtn.classList.add('is-loading');
            submitBtn.disabled = true;
        });
    }
</script>
</body>
</html>