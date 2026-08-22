<?php
require_once __DIR__ . '/../includes/functions.php';

startSecureSession();

// Clear admin session variables
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_role']);

// Destroy the session completely (optional but safe)
session_destroy();

header('Location: login.php');
exit;