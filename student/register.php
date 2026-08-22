<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

startSecureSession();

// If already logged in, go to dashboard (root)
if (!empty($_SESSION['user_id'])) {
    header('Location: ../dashboard.php');
    exit;
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired. Please try again.';
    } else {
        $pdo = getDbConnection();

        // Tighter than the login limiter on purpose: this endpoint lets
        // anyone who knows a student ID claim that account by setting its
        // password, so it's the one most worth slowing down against
        // someone scripting through sequential/guessable student IDs.
        if (isRateLimited($pdo, 'register', 5, 600)) {
            $error = 'Too many attempts. Please wait a few minutes and try again, or contact the election administrator.';
        } else {
        $studentId = trim($_POST['student_id'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($studentId === '' || $password === '' || $confirm === '') {
            $error = 'Please fill in all fields.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            recordAttempt($pdo, 'register');

            $stmt = $pdo->prepare('
                SELECT id, name, registration_status
                FROM users
                WHERE student_id = :student_id
            ');
            $stmt->execute(['student_id' => $studentId]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = 'Student ID not recognized. Please contact the admin.';
            } elseif ($user['registration_status'] === 'suspended') {
                $error = 'This account has been suspended. Please contact the election administrator.';
            } elseif ($user['registration_status'] !== 'unregistered') {
                $error = 'This account is already activated. Please log in.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("
                    UPDATE users
                    SET password = :password,
                        registration_status = 'active'
                    WHERE id = :id
                ");
                $update->execute([
                    'password' => $hash,
                    'id' => $user['id']
                ]);

                $success = true;
                header('Refresh: 2; URL=../login.php?registered=1');
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate Account | School Elections</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        body { margin: 0; min-height: 100vh; }

        .bg-photo {
            position: fixed; inset: 0; z-index: -1;
            background-image: url('../assets/school-bg.jpg');
            background-size: cover; background-position: center;
            filter: blur(6px); transform: scale(1.1);
        }
        .bg-overlay { position: fixed; inset: 0; z-index: -1; background: rgba(0, 0, 0, 0.35); }

        .page-center {
            position: relative; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }

        .page-center .card {
            background: rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
            border-radius: 16px;
            padding: 2rem 2.25rem 2.25rem;
            width: 100%;
            max-width: 420px;
        }

        .page-center .logo-area { text-align: center; margin-bottom: 0.5rem; }
        .page-center .logo { width: 110px; height: 110px; object-fit: contain; }

        .page-center .card-title,
        .page-center label {
            color: #ffffff;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.4);
        }
        .page-center .card-title { text-align: center; margin-top: 0; }
        .page-center .subtitle {
            color: rgba(255,255,255,0.85);
            text-align: center;
            font-size: 0.88rem;
            margin: -0.5rem 0 1.25rem;
            text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }

        .page-center label {
            font-size: 1rem; font-weight: 600; display: block;
            margin-top: 1rem; margin-bottom: 0.35rem;
        }

        .page-center input[type="text"],
        .page-center input[type="password"] {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.4);
            width: 100%; padding: 0.6rem 0.75rem; border-radius: 8px;
            font-size: 0.95rem; box-sizing: border-box;
        }
        .page-center input[type="text"]:focus,
        .page-center input[type="password"]:focus {
            outline: none; border-color: #84cc16;
            box-shadow: 0 0 0 3px rgba(132, 204, 22, 0.4);
        }

        /* Kill native browser reveal-password icons so only our custom
           eye toggle shows (same fix as the login page). */
        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear { display: none; width: 0; height: 0; }
        input[type="password"]::-webkit-credentials-auto-fill-button,
        input[type="password"]::-webkit-strong-password-auto-fill-button {
            visibility: hidden; display: none !important; pointer-events: none;
            position: absolute; right: 0;
        }

        .password-field { position: relative; display: flex; align-items: stretch; }
        .password-field input { flex: 1; padding-right: 2.5rem; }
        .toggle-password-btn {
            position: absolute; right: 0.5rem; top: 50%; transform: translateY(-50%);
            background: none; border: none; padding: 0.25rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center; line-height: 0;
        }
        .toggle-password-btn svg { width: 20px; height: 20px; stroke: #333333; }
        .toggle-password-btn .icon-eye { display: none; }
        .toggle-password-btn .icon-eye-off { display: block; }
        .toggle-password-btn.is-visible .icon-eye { display: block; }
        .toggle-password-btn.is-visible .icon-eye-off { display: none; }

        .page-center .btn-primary {
            background: #84cc16; border-color: #84cc16; color: #052e16;
            width: 100%; margin-top: 1.5rem;
        }
        .page-center .btn-primary:hover { background: #65a30d; border-color: #65a30d; }

        .page-center a { color: #bef264; }
        .page-center a:hover { color: #d9f99d; }

        .footer-links { margin-top: 1.25rem; text-align: center; font-size: 0.85rem; }

        .success-msg {
            text-align: center; color: #d9f99d; font-weight: 600;
            padding: 1rem 0; text-shadow: 0 1px 3px rgba(0,0,0,0.4);
        }
    </style>
</head>
<body>
<div class="bg-photo"></div>
<div class="bg-overlay"></div>
<div class="page-center">
    <div class="card">
        <div class="logo-area">
            <img src="../assets/logo.png" alt="FICT Logo" class="logo">
        </div>
        <h2 class="card-title">Activate Your Account</h2>
        <p class="subtitle">Enter your Student ID and set a password to activate your voting account.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-msg">✅ Account activated! Redirecting you to the login page…</div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

                <label for="student_id">Student ID</label>
                <input type="text" id="student_id" name="student_id" required autofocus autocomplete="username">

                <label for="password">New Password (min. 6 characters)</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required autocomplete="new-password">
                    <button type="button" class="toggle-password-btn" data-target="password" aria-label="Show password">
                        <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a20.3 20.3 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>

                <label for="confirm_password">Confirm Password</label>
                <div class="password-field">
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                    <button type="button" class="toggle-password-btn" data-target="confirm_password" aria-label="Show password">
                        <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg class="icon-eye-off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.3 20.3 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 7 11 7a20.3 20.3 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>

                <button type="submit" class="btn btn-primary">Activate & Log In</button>
            </form>
        <?php endif; ?>

        <div class="footer-links">
            <a href="../login.php">Back to Login</a>
        </div>
    </div>
</div>
<script>
    document.querySelectorAll('.toggle-password-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            this.classList.toggle('is-visible', isHidden);
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });
</script>
</body>
</html>