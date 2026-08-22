<?php
/**
 * admin/api/settings.php
 *
 * CRUD for the reference lists that power dropdowns elsewhere in the admin
 * panel: Departments, Majors (scoped to a department), and Year Levels.
 * These used to be hardcoded JS arrays inside admin/dashboard.php — this
 * makes them admin-editable instead. See migration_settings.sql for the
 * schema and why users.department/users.major/users.year_level/
 * elections.department deliberately stay plain text with no foreign key.
 *
 * Also serves election LOGOS:
 *   - Each department can have its own logo (departments.logo), shown on
 *     that department's DSG elections everywhere in the app.
 *   - A single site-wide SSG logo lives in the generic site_settings
 *     key/value table (SSG isn't a department, so it has nowhere else to
 *     live). See migration_logos.sql.
 * Both are stored the same way candidate photos already are — as the
 * browser's own base64 data: URL, directly in Postgres, capped at 2MB.
 *
 * A single "type" field ('department' | 'major' | 'year_level' |
 * 'department_logo' | 'site_setting') routes POST/PUT/DELETE to the right
 * sub-resource, since these are all small and closely related — keeps
 * this to one file instead of several near-identical ones.
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

function nextSortOrder(PDO $pdo, string $table, ?array $where = null): int
{
    $sql = "SELECT COALESCE(MAX(sort_order), -1) FROM $table";
    if ($where) {
        $sql .= ' WHERE ' . $where['clause'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($where['params'] ?? []);
    return ((int) $stmt->fetchColumn()) + 1;
}

// Rough base64 -> decoded-byte-size estimate, good enough for a size cap.
// Same helper as admin/api/elections.php uses for candidate photos.
function base64ApproxBytes(string $base64): int
{
    $comma = strpos($base64, ',');
    $encoded = $comma !== false ? substr($base64, $comma + 1) : $base64;
    return (int) (strlen($encoded) * 3 / 4);
}

// Validates an uploaded logo image: must be a real data:image/ URL, capped
// at 2MB (matches the candidate-photo cap in admin/api/elections.php).
// Returns an error string, or null if the image is fine (or empty/absent,
// which is valid — it means "clear the logo").
function validateLogoUpload(?string $value): ?string
{
    if (empty($value)) {
        return null; // clearing the logo — always allowed
    }
    if (strpos($value, 'data:image/') !== 0) {
        return 'Invalid image data.';
    }
    if (base64ApproxBytes($value) > 2 * 1024 * 1024) {
        return 'Logo is too large (max 2MB) — please use a smaller image.';
    }
    return null;
}

// ------------------------------------------------------------------
// GET: everything needed to populate dropdowns + the Settings panel
// ------------------------------------------------------------------
if ($method === 'GET') {
    $departments = $pdo->query('SELECT code, name, sort_order, logo FROM departments ORDER BY sort_order, code')->fetchAll();
    $majors = $pdo->query('SELECT id, department_code, name, sort_order FROM majors ORDER BY department_code, sort_order, name')->fetchAll();
    $yearLevels = $pdo->query('SELECT id, name, sort_order FROM year_levels ORDER BY sort_order, name')->fetchAll();
    $ssgLogo = getSiteSetting($pdo, 'ssg_logo');

    respond([
        'departments' => $departments,
        'majors' => $majors,
        'year_levels' => $yearLevels,
        'site_settings' => ['ssg_logo' => $ssgLogo],
    ]);
}

// Every other method is a write — require CSRF from the JSON body.
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['error' => 'Invalid request body.'], 400);
}
if (!verifyCsrfToken($body['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

$type = $body['type'] ?? '';
if (!in_array($type, ['department', 'major', 'year_level', 'department_logo', 'site_setting'], true)) {
    respond(['error' => 'Invalid type.'], 400);
}

// ------------------------------------------------------------------
// POST: create
// ------------------------------------------------------------------
if ($method === 'POST') {
    if ($type === 'department') {
        $code = trim($body['code'] ?? '');
        $name = trim($body['name'] ?? '') ?: $code;
        if ($code === '') respond(['error' => 'Department code is required.'], 400);
        if (mb_strlen($code) > 20) respond(['error' => 'Department code is too long (max 20 characters).'], 400);
        if (mb_strlen($name) > 100) respond(['error' => 'Department name is too long (max 100 characters).'], 400);

        $check = $pdo->prepare('SELECT id FROM departments WHERE code = :code');
        $check->execute(['code' => $code]);
        if ($check->fetch()) respond(['error' => 'A department with that code already exists.'], 409);

        $sort = nextSortOrder($pdo, 'departments');
        $pdo->prepare('INSERT INTO departments (code, name, sort_order) VALUES (:code, :name, :sort)')
            ->execute(['code' => $code, 'name' => $name, 'sort' => $sort]);
        respond(['success' => true]);
    }

    if ($type === 'major') {
        $departmentCode = trim($body['department_code'] ?? '');
        $name = trim($body['name'] ?? '');
        if ($departmentCode === '' || $name === '') respond(['error' => 'Department and major name are required.'], 400);
        if (mb_strlen($name) > 100) respond(['error' => 'Major name is too long (max 100 characters).'], 400);

        $deptCheck = $pdo->prepare('SELECT code FROM departments WHERE code = :code');
        $deptCheck->execute(['code' => $departmentCode]);
        if (!$deptCheck->fetch()) respond(['error' => 'That department does not exist.'], 404);

        $dupCheck = $pdo->prepare('SELECT id FROM majors WHERE department_code = :dc AND name = :name');
        $dupCheck->execute(['dc' => $departmentCode, 'name' => $name]);
        if ($dupCheck->fetch()) respond(['error' => 'That major already exists for this department.'], 409);

        $sort = nextSortOrder($pdo, 'majors', ['clause' => 'department_code = :dc', 'params' => ['dc' => $departmentCode]]);
        $pdo->prepare('INSERT INTO majors (department_code, name, sort_order) VALUES (:dc, :name, :sort)')
            ->execute(['dc' => $departmentCode, 'name' => $name, 'sort' => $sort]);
        respond(['success' => true]);
    }

    if ($type === 'year_level') {
        $name = trim($body['name'] ?? '');
        if ($name === '') respond(['error' => 'Year level name is required.'], 400);
        if (mb_strlen($name) > 20) respond(['error' => 'Year level name is too long (max 20 characters).'], 400);

        $check = $pdo->prepare('SELECT id FROM year_levels WHERE name = :name');
        $check->execute(['name' => $name]);
        if ($check->fetch()) respond(['error' => 'That year level already exists.'], 409);

        $sort = nextSortOrder($pdo, 'year_levels');
        $pdo->prepare('INSERT INTO year_levels (name, sort_order) VALUES (:name, :sort)')
            ->execute(['name' => $name, 'sort' => $sort]);
        respond(['success' => true]);
    }

    // 'department_logo' and 'site_setting' are only ever updates to an
    // existing row (a department, or a known settings key) — there's
    // nothing meaningful to "create" for them, so POST falls through to
    // "Method not allowed" for those types. Use PUT to set/clear a logo.
    respond(['error' => 'Method not allowed for this type.'], 405);
}

// ------------------------------------------------------------------
// PUT: update (including reorder + logo upload), with an explicit bulk-
// update when a rename actually changes a value that's stored elsewhere
// (department code, major name, year level name) — never silent, always
// in the same transaction as the rename itself.
// ------------------------------------------------------------------
if ($method === 'PUT') {
    if ($type === 'department') {
        $oldCode = trim($body['old_code'] ?? '');
        $newCode = trim($body['code'] ?? $oldCode);
        $name = trim($body['name'] ?? '') ?: $newCode;
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : null;

        if ($oldCode === '') respond(['error' => 'Missing department code.'], 400);
        $existing = $pdo->prepare('SELECT code FROM departments WHERE code = :code');
        $existing->execute(['code' => $oldCode]);
        if (!$existing->fetch()) respond(['error' => 'Department not found.'], 404);

        if ($newCode === '') respond(['error' => 'Department code cannot be empty.'], 400);
        if (mb_strlen($newCode) > 20) respond(['error' => 'Department code is too long (max 20 characters).'], 400);
        if (mb_strlen($name) > 100) respond(['error' => 'Department name is too long (max 100 characters).'], 400);

        if ($newCode !== $oldCode) {
            $dupCheck = $pdo->prepare('SELECT id FROM departments WHERE code = :code AND code != :old');
            $dupCheck->execute(['code' => $newCode, 'old' => $oldCode]);
            if ($dupCheck->fetch()) respond(['error' => 'A department with that code already exists.'], 409);
        }

        try {
            $pdo->beginTransaction();

            $fields = ['name = :name'];
            $values = ['old' => $oldCode, 'name' => $name];
            if ($newCode !== $oldCode) {
                $fields[] = 'code = :new_code';
                $values['new_code'] = $newCode;
            }
            if ($sortOrder !== null) {
                $fields[] = 'sort_order = :sort';
                $values['sort'] = $sortOrder;
            }
            $pdo->prepare('UPDATE departments SET ' . implode(', ', $fields) . ' WHERE code = :old')->execute($values);

            $affected = ['users' => 0, 'elections' => 0];
            if ($newCode !== $oldCode) {
                // majors.department_code follows automatically via
                // ON UPDATE CASCADE (see migration_settings.sql) — but
                // users.department / elections.department are plain text
                // with no FK, so those need an explicit bulk-update here,
                // in the same transaction, so nothing is left pointing at
                // a department code that no longer exists.
                $u = $pdo->prepare('UPDATE users SET department = :new WHERE department = :old');
                $u->execute(['new' => $newCode, 'old' => $oldCode]);
                $affected['users'] = $u->rowCount();

                $e = $pdo->prepare('UPDATE elections SET department = :new WHERE department = :old');
                $e->execute(['new' => $newCode, 'old' => $oldCode]);
                $affected['elections'] = $e->rowCount();
            }

            $pdo->commit();
            respond(['success' => true, 'updated_students' => $affected['users'], 'updated_elections' => $affected['elections']]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update department failed: ' . $e->getMessage());
            respond(['error' => 'Could not save changes.'], 500);
        }
    }

    if ($type === 'department_logo') {
        $code = trim($body['code'] ?? '');
        if ($code === '') respond(['error' => 'Missing department code.'], 400);

        $existing = $pdo->prepare('SELECT code FROM departments WHERE code = :code');
        $existing->execute(['code' => $code]);
        if (!$existing->fetch()) respond(['error' => 'Department not found.'], 404);

        // An absent/empty 'logo' means "clear it" — that's intentional
        // (see the Remove Logo button in the admin panel), not an error.
        $logoInput = $body['logo'] ?? null;
        if ($error = validateLogoUpload($logoInput)) {
            respond(['error' => $error], 400);
        }

        $pdo->prepare('UPDATE departments SET logo = :logo WHERE code = :code')
            ->execute(['logo' => $logoInput ?: null, 'code' => $code]);
        respond(['success' => true]);
    }

    if ($type === 'site_setting') {
        // Only known keys are writable here — this endpoint is deliberately
        // not a general-purpose key/value store the client can write
        // anything to; it's just where the SSG logo (and future site-wide
        // assets like it) live.
        $key = trim($body['key'] ?? '');
        if (!in_array($key, ['ssg_logo'], true)) {
            respond(['error' => 'Unknown setting.'], 400);
        }

        $valueInput = $body['value'] ?? null;
        if ($error = validateLogoUpload($valueInput)) {
            respond(['error' => $error], 400);
        }
        $value = $valueInput ?: null;

        $pdo->prepare('
            INSERT INTO site_settings (setting_key, setting_value, updated_at)
            VALUES (:k, :v, NOW())
            ON CONFLICT (setting_key) DO UPDATE SET setting_value = :v2, updated_at = NOW()
        ')->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
        respond(['success' => true]);
    }

    if ($type === 'major') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid major.'], 400);

        $existing = $pdo->prepare('SELECT id, department_code, name FROM majors WHERE id = :id');
        $existing->execute(['id' => $id]);
        $current = $existing->fetch();
        if (!$current) respond(['error' => 'Major not found.'], 404);

        $newName = trim($body['name'] ?? $current['name']);
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : null;
        if ($newName === '') respond(['error' => 'Major name cannot be empty.'], 400);
        if (mb_strlen($newName) > 100) respond(['error' => 'Major name is too long (max 100 characters).'], 400);

        try {
            $pdo->beginTransaction();

            $fields = ['name = :name'];
            $values = ['id' => $id, 'name' => $newName];
            if ($sortOrder !== null) {
                $fields[] = 'sort_order = :sort';
                $values['sort'] = $sortOrder;
            }
            $pdo->prepare('UPDATE majors SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($values);

            $updatedStudents = 0;
            if ($newName !== $current['name']) {
                // users.major is plain text with no FK — bulk-update any
                // student currently recorded under the old major name so
                // nothing is left pointing at a major that no longer exists.
                $u = $pdo->prepare('UPDATE users SET major = :new WHERE major = :old AND department = :dc');
                $u->execute(['new' => $newName, 'old' => $current['name'], 'dc' => $current['department_code']]);
                $updatedStudents = $u->rowCount();
            }

            $pdo->commit();
            respond(['success' => true, 'updated_students' => $updatedStudents]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update major failed: ' . $e->getMessage());
            respond(['error' => 'Could not save changes.'], 500);
        }
    }

    if ($type === 'year_level') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid year level.'], 400);

        $existing = $pdo->prepare('SELECT id, name FROM year_levels WHERE id = :id');
        $existing->execute(['id' => $id]);
        $current = $existing->fetch();
        if (!$current) respond(['error' => 'Year level not found.'], 404);

        $newName = trim($body['name'] ?? $current['name']);
        $sortOrder = array_key_exists('sort_order', $body) ? (int) $body['sort_order'] : null;
        if ($newName === '') respond(['error' => 'Year level name cannot be empty.'], 400);
        if (mb_strlen($newName) > 20) respond(['error' => 'Year level name is too long (max 20 characters).'], 400);

        if ($newName !== $current['name']) {
            $dupCheck = $pdo->prepare('SELECT id FROM year_levels WHERE name = :name AND id != :id');
            $dupCheck->execute(['name' => $newName, 'id' => $id]);
            if ($dupCheck->fetch()) respond(['error' => 'That year level already exists.'], 409);
        }

        try {
            $pdo->beginTransaction();

            $fields = ['name = :name'];
            $values = ['id' => $id, 'name' => $newName];
            if ($sortOrder !== null) {
                $fields[] = 'sort_order = :sort';
                $values['sort'] = $sortOrder;
            }
            $pdo->prepare('UPDATE year_levels SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($values);

            $affected = ['users' => 0, 'positions' => 0];
            if ($newName !== $current['name']) {
                $u = $pdo->prepare('UPDATE users SET year_level = :new WHERE year_level = :old');
                $u->execute(['new' => $newName, 'old' => $current['name']]);
                $affected['users'] = $u->rowCount();

                // A position's "limit to see" (year_restriction) also
                // stores this same text value — keep it in sync too.
                $p = $pdo->prepare('UPDATE election_positions SET year_restriction = :new WHERE year_restriction = :old');
                $p->execute(['new' => $newName, 'old' => $current['name']]);
                $affected['positions'] = $p->rowCount();
            }

            $pdo->commit();
            respond(['success' => true, 'updated_students' => $affected['users'], 'updated_positions' => $affected['positions']]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update year level failed: ' . $e->getMessage());
            respond(['error' => 'Could not save changes.'], 500);
        }
    }
}

// ------------------------------------------------------------------
// DELETE: blocked if anything currently uses the value, same pattern as
// "can't delete an election with votes already cast" elsewhere in the app.
// ------------------------------------------------------------------
if ($method === 'DELETE') {
    if ($type === 'department') {
        $code = trim($body['code'] ?? '');
        if ($code === '') respond(['error' => 'Missing department code.'], 400);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE department = :code');
        $stmt->execute(['code' => $code]);
        $uCount = (int) $stmt->fetchColumn();

        $stmt2 = $pdo->prepare('SELECT COUNT(*) FROM elections WHERE department = :code');
        $stmt2->execute(['code' => $code]);
        $eCount = (int) $stmt2->fetchColumn();

        if ($uCount > 0 || $eCount > 0) {
            $parts = [];
            if ($uCount > 0) $parts[] = "$uCount student" . ($uCount === 1 ? '' : 's');
            if ($eCount > 0) $parts[] = "$eCount election" . ($eCount === 1 ? '' : 's');
            respond(['error' => 'Cannot delete — ' . implode(' and ', $parts) . ' still use this department. Reassign them first.'], 409);
        }

        // Majors under this department cascade-delete automatically
        // (see migration_settings.sql). Its logo (if any) goes with the
        // row — nothing else references it.
        $pdo->prepare('DELETE FROM departments WHERE code = :code')->execute(['code' => $code]);
        respond(['success' => true]);
    }

    if ($type === 'major') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid major.'], 400);

        $stmt = $pdo->prepare('SELECT department_code, name FROM majors WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $major = $stmt->fetch();
        if (!$major) respond(['error' => 'Major not found.'], 404);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE department = :dc AND major = :name');
        $countStmt->execute(['dc' => $major['department_code'], 'name' => $major['name']]);
        $count = (int) $countStmt->fetchColumn();
        if ($count > 0) {
            respond(['error' => "Cannot delete — $count student" . ($count === 1 ? '' : 's') . ' still have this major. Reassign them first.'], 409);
        }

        $pdo->prepare('DELETE FROM majors WHERE id = :id')->execute(['id' => $id]);
        respond(['success' => true]);
    }

    if ($type === 'year_level') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) respond(['error' => 'Invalid year level.'], 400);

        $stmt = $pdo->prepare('SELECT name FROM year_levels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $yl = $stmt->fetch();
        if (!$yl) respond(['error' => 'Year level not found.'], 404);

        $uStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE year_level = :name');
        $uStmt->execute(['name' => $yl['name']]);
        $uCount = (int) $uStmt->fetchColumn();

        $pStmt = $pdo->prepare('SELECT COUNT(*) FROM election_positions WHERE year_restriction = :name');
        $pStmt->execute(['name' => $yl['name']]);
        $pCount = (int) $pStmt->fetchColumn();

        if ($uCount > 0 || $pCount > 0) {
            $parts = [];
            if ($uCount > 0) $parts[] = "$uCount student" . ($uCount === 1 ? '' : 's');
            if ($pCount > 0) $parts[] = "$pCount position" . ($pCount === 1 ? '' : 's') . ' restricted to it';
            respond(['error' => 'Cannot delete — ' . implode(' and ', $parts) . ' still use this year level.'], 409);
        }

        $pdo->prepare('DELETE FROM year_levels WHERE id = :id')->execute(['id' => $id]);
        respond(['success' => true]);
    }

    // 'department_logo' and 'site_setting' are cleared via PUT with an
    // empty value (see above) rather than DELETE — a logo isn't its own
    // row, it's a field on one, so there's nothing distinct to DELETE.
    respond(['error' => 'Method not allowed for this type.'], 405);
}

respond(['error' => 'Method not allowed.'], 405);