<?php
/**
 * admin/index.php is just a router now — visiting /admin/ (or /admin/index.php
 * directly) should send you to the right place depending on whether you're
 * already logged in, rather than being its own page with its own content.
 *
 *   logged in      -> dashboard.php  (main admin hub)
 *   not logged in  -> login.php
 */
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();

header('Location: ' . (!empty($_SESSION['is_admin']) ? 'dashboard.php' : 'login.php'));
exit;