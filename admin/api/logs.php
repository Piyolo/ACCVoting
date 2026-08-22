<?php
/**
 * admin/api/logs.php
 *
 * Real DB-backed activity log for the admin panel's Dashboard "Recent
 * Activity" card and full Logs tab. Every admin-triggered action (create/
 * edit/delete election, register/edit/delete student, etc.) posts a short
 * line here from the client after the action succeeds.
 *
 * The admin's username is taken from their own session — never trusted
 * from the request body — so the log stays a reliable "who did what"
 * record even with multiple admins sharing the panel.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();
header('Content-Type: application/json');

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// ---------- GET: most recent activity first ----------
if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
    $limit = max(1, min(500, $limit));

    $stmt = $pdo->prepare('
        SELECT id, admin_username, action, created_at
        FROM activity_logs
        ORDER BY created_at DESC, id DESC
        LIMIT :limit
    ');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    respond($stmt->fetchAll());
}

// ---------- POST: record a new log line ----------
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) respond(['error' => 'Invalid request body.'], 400);
    if (!verifyCsrfToken($input['csrf_token'] ?? null)) {
        respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
    }

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') respond(['error' => 'Missing action text.'], 400);
    if (mb_strlen($action) > 500) $action = mb_substr($action, 0, 500);

    $stmt = $pdo->prepare('INSERT INTO activity_logs (admin_username, action) VALUES (:username, :action)');
    $stmt->execute([
        'username' => $_SESSION['admin_username'] ?? 'admin',
        'action' => $action,
    ]);

    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);