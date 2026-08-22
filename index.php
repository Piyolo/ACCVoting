<?php
/**
 * index.php is just a router now — visiting "/" (or "/index.php" directly,
 * which is what most servers treat as the default document) should send
 * you to the right place rather than straight into the login form.
 *
 *   logged in      -> dashboard.php
 *   not logged in  -> landingpage.php
 *
 * The actual login form now lives at login.php.
 */
require_once __DIR__ . '/includes/functions.php';

startSecureSession();

header('Location: ' . (!empty($_SESSION['user_id']) ? 'dashboard.php' : 'landingpage.php'));
exit;