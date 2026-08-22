<?php
/**
 * admin/api/students.php
 *
 * Real DB-backed CRUD for the admin "Students" panel.
 * Talks JSON in both directions. Every write requires the admin session
 * (requireAdminLogin) AND a valid CSRF token in the JSON body.
 *
 * Frontend field names <-> DB columns:
 *   studentId  <-> student_id
 *   fullName   <-> name
 *   department <-> department
 *   major      <-> major
 *   section    <-> section
 *   yearLevel  <-> year_level
 *   email      <-> email
 *   status     <-> registration_status  ('unregistered' | 'active' | 'suspended')
 *   hasVoted   <-> has_voted            (0 / 1)
 *   password   -> hashed with password_hash() before it ever touches the DB.
 *                 We never send a password back out — editing leaves it
 *                 untouched unless a new one is supplied.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

startSecureSession();
requireAdminLogin();

header('Content-Type: application/json');

$pdo = getDbConnection();

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

// Field limits matching the actual users table column widths. Without
// this, an over-length value (student ID, name, etc.) reaches Postgres
// and fails as a raw "value too long for type character varying(N)"
// error instead of a clear message here.
function checkFieldLengths(array $fields): ?string
{
    $limits = [
        'Student ID' => [$fields['student_id'] ?? null, 20],
        'Full name' => [$fields['fullName'] ?? null, 100],
        'Department' => [$fields['department'] ?? null, 50],
        'Major' => [$fields['major'] ?? null, 100],
        'Section' => [$fields['section'] ?? null, 20],
        'Year level' => [$fields['yearLevel'] ?? null, 20],
        'Email' => [$fields['email'] ?? null, 100],
    ];
    foreach ($limits as $label => [$value, $max]) {
        if ($value !== null && mb_strlen((string) $value) > $max) {
            return "$label is too long (max $max characters).";
        }
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------- GET: list all students ----------
if ($method === 'GET') {
    $stmt = $pdo->query('
        SELECT id, student_id, name, department, major, section, year_level,
               email, has_voted, registration_status
        FROM users
        ORDER BY name ASC
    ');
    $rows = $stmt->fetchAll();

    // Which (user, election) pairs has each student actually voted in?
    // Computed fresh from votes/election_positions rather than trusting the
    // single legacy has_voted flag, so this stays accurate even when a
    // student is eligible for more than one election at once.
    $voteMap = []; // user_id => [election_id, election_id, ...]
    $voteStmt = $pdo->query('
        SELECT DISTINCT v.user_id, ep.election_id
        FROM votes v
        JOIN election_positions ep ON ep.position_id = v.position_id
    ');
    foreach ($voteStmt->fetchAll() as $row) {
        $voteMap[(int) $row['user_id']][] = (int) $row['election_id'];
    }

    $out = array_map(function ($r) use ($voteMap) {
        return [
            'id' => (int) $r['id'],
            'student_id' => $r['student_id'],
            'name' => $r['name'],
            'department' => $r['department'],
            'major' => $r['major'],
            'section' => $r['section'],
            'year_level' => $r['year_level'],
            'email' => $r['email'],
            'has_voted' => (int) $r['has_voted'],
            'status' => $r['registration_status'] ?: 'unregistered',
            'voted_election_ids' => $voteMap[(int) $r['id']] ?? [],
        ];
    }, $rows);

    respond($out);
}

// Every other method is a write — require CSRF from the JSON body.
$body = readJsonBody();
if (!verifyCsrfToken($body['csrf_token'] ?? null)) {
    respond(['error' => 'Your session expired. Please refresh and try again.'], 403);
}

// ---------- POST: create a new student, OR bulk-import from CSV ----------
if ($method === 'POST') {
    // Bulk import — rows are parsed client-side (see parseCSV()/
    // rowsToStudentRecords() in dashboard.php) and sent here as plain
    // JSON, one object per row. This is a best-effort import: rows that
    // fail validation are SKIPPED (with a reason reported back), not
    // treated as a reason to fail the whole batch — losing 3 rows out of
    // 300 to a typo shouldn't block the other 297 from being imported.
    // Imported students always land as 'unregistered' with no password
    // (self-activation flow), matching what happens when the single
    // Register Student form is submitted with no password — bulk import
    // deliberately never accepts passwords over CSV.
    if (!empty($body['bulkImport'])) {
        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            respond(['error' => 'No rows to import.'], 400);
        }
        if (count($rows) > 2000) {
            respond(['error' => 'Too many rows in one import (max 2000) — split into smaller files.'], 400);
        }

        // Department/Major/Year Level are validated against the actual
        // managed lists (see admin/api/settings.php) — this is the one
        // place those lists are enforced, since a typo here silently
        // creates a value that will never appear in any dropdown/filter
        // afterward.
        $validDepartments = $pdo->query('SELECT code FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        $validYearLevels = $pdo->query('SELECT name FROM year_levels')->fetchAll(PDO::FETCH_COLUMN);
        $majorsByDept = [];
        foreach ($pdo->query('SELECT department_code, name FROM majors')->fetchAll() as $m) {
            $majorsByDept[$m['department_code']][] = $m['name'];
        }

        $existingIds = array_flip($pdo->query('SELECT student_id FROM users')->fetchAll(PDO::FETCH_COLUMN));

        $seenInFile = [];
        $toInsert = [];
        $skipped = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 1;
            $studentId = trim((string) ($row['student_id'] ?? ''));
            $name = trim((string) ($row['fullName'] ?? ''));
            $department = trim((string) ($row['department'] ?? ''));
            $major = trim((string) ($row['major'] ?? ''));
            $section = trim((string) ($row['section'] ?? ''));
            $yearLevel = trim((string) ($row['yearLevel'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));

            $reason = null;
            if ($studentId === '' || $name === '' || $department === '' || $major === '' || $section === '' || $yearLevel === '') {
                $reason = 'Missing a required field.';
            } elseif (mb_strlen($studentId) > 20) {
                $reason = 'Student ID too long (max 20 characters).';
            } elseif (mb_strlen($name) > 100) {
                $reason = 'Name too long (max 100 characters).';
            } elseif (mb_strlen($section) > 20) {
                $reason = 'Section too long (max 20 characters).';
            } elseif ($email !== '' && mb_strlen($email) > 100) {
                $reason = 'Email too long (max 100 characters).';
            } elseif (isset($existingIds[$studentId])) {
                $reason = 'Student ID already registered.';
            } elseif (isset($seenInFile[$studentId])) {
                $reason = 'Duplicate Student ID within this file.';
            } elseif (!in_array($department, $validDepartments, true)) {
                $reason = "Unknown department \"$department\" — add it in Settings first.";
            } elseif (!in_array($major, $majorsByDept[$department] ?? [], true)) {
                $reason = "Unknown major \"$major\" for department \"$department\" — add it in Settings first.";
            } elseif (!in_array($yearLevel, $validYearLevels, true)) {
                $reason = "Unknown year level \"$yearLevel\" — add it in Settings first.";
            }

            if ($reason !== null) {
                $skipped[] = ['row' => $rowNum, 'student_id' => $studentId !== '' ? $studentId : '(blank)', 'reason' => $reason];
                continue;
            }

            $seenInFile[$studentId] = true;
            $toInsert[] = compact('studentId', 'name', 'department', 'major', 'section', 'yearLevel', 'email');
        }

        $imported = 0;
        if ($toInsert) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('
                    INSERT INTO users (student_id, name, department, major, section, year_level, email, password, registration_status, has_voted)
                    VALUES (:student_id, :name, :department, :major, :section, :year_level, :email, NULL, \'unregistered\', 0)
                ');
                foreach ($toInsert as $row) {
                    $stmt->execute([
                        'student_id' => $row['studentId'],
                        'name' => $row['name'],
                        'department' => $row['department'],
                        'major' => $row['major'],
                        'section' => $row['section'],
                        'year_level' => $row['yearLevel'],
                        'email' => $row['email'] !== '' ? $row['email'] : null,
                    ]);
                    $imported++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Bulk student import failed: ' . $e->getMessage());
                respond(['error' => 'Import failed partway through — no rows from this batch were saved.'], 500);
            }
        }

        respond(['success' => true, 'imported' => $imported, 'skipped' => $skipped]);
    }

    $studentId  = trim($body['student_id'] ?? '');
    $name       = trim($body['fullName'] ?? '');
    $department = trim($body['department'] ?? '');
    $major      = trim($body['major'] ?? '');
    $section    = trim($body['section'] ?? '');
    $yearLevel  = trim($body['yearLevel'] ?? '');
    $email      = trim($body['email'] ?? '');
    $password   = (string) ($body['password'] ?? '');

    if ($studentId === '' || $name === '' || $department === '' || $major === '' || $section === '' || $yearLevel === '') {
        respond(['error' => 'All required fields must be filled.'], 400);
    }
    if ($lengthError = checkFieldLengths(['student_id' => $studentId, 'fullName' => $name, 'department' => $department, 'major' => $major, 'section' => $section, 'yearLevel' => $yearLevel, 'email' => $email])) {
        respond(['error' => $lengthError], 400);
    }
    if ($password !== '' && strlen($password) < 6) {
        respond(['error' => 'Password should be at least 6 characters.'], 400);
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE student_id = :id');
    $check->execute(['id' => $studentId]);
    if ($check->fetch()) {
        respond(['error' => 'That Student ID is already registered.'], 409);
    }

    // If the admin sets a password now, the account is active immediately.
    // If left blank, the student activates their own account via the
    // self-registration page (registration_status stays 'unregistered').
    $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
    $status = $password !== '' ? 'active' : 'unregistered';

    $stmt = $pdo->prepare('
        INSERT INTO users (student_id, name, department, major, section, year_level, email, password, registration_status, has_voted)
        VALUES (:student_id, :name, :department, :major, :section, :year_level, :email, :password, :status, 0)
    ');
    $stmt->execute([
        'student_id' => $studentId,
        'name' => $name,
        'department' => $department,
        'major' => $major,
        'section' => $section,
        'year_level' => $yearLevel,
        'email' => $email !== '' ? $email : null,
        'password' => $hash,
        'status' => $status,
    ]);

    respond(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
}

// ---------- PUT: update an existing student ----------
if ($method === 'PUT') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        respond(['error' => 'Invalid student ID.'], 400);
    }

    $existing = $pdo->prepare('SELECT id, student_id FROM users WHERE id = :id');
    $existing->execute(['id' => $id]);
    $current = $existing->fetch();
    if (!$current) {
        respond(['error' => 'Student not found.'], 404);
    }

    // Special action: actually deletes the student's cast votes for ONE
    // specific election (not just flipping a display flag), so the
    // Students panel's real per-election status reflects it immediately
    // and the student can genuinely vote again in that election. This is
    // what the admin dashboard's "Reset" button on a student's voting
    // status now calls — previously it only toggled the legacy has_voted
    // column below, which doesn't affect what's actually shown per
    // election or what vote.php checks, so the button did nothing real.
    if (!empty($body['resetVotesForElection'])) {
        $eid = (int) $body['resetVotesForElection'];
        $pdo->prepare('
            DELETE FROM votes
            WHERE user_id = :uid
              AND position_id IN (SELECT position_id FROM election_positions WHERE election_id = :eid)
        ')->execute(['uid' => $id, 'eid' => $eid]);

        // Keep the legacy "voted somewhere, ever" flag consistent: only
        // true if the student still has at least one vote left in any
        // election after this reset.
        $remainStmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE user_id = :uid');
        $remainStmt->execute(['uid' => $id]);
        $remaining = (int) $remainStmt->fetchColumn();
        $pdo->prepare('UPDATE users SET has_voted = :hv WHERE id = :id')
            ->execute(['hv' => $remaining > 0 ? 1 : 0, 'id' => $id]);

        respond(['success' => true]);
    }

    $fields = [];
    $values = ['id' => $id];

    $map = [
        'student_id'  => 'student_id',
        'fullName'    => 'name',
        'department'  => 'department',
        'major'       => 'major',
        'section'     => 'section',
        'yearLevel'   => 'year_level',
        'email'       => 'email',
        'status'      => 'registration_status',
    ];

    if ($lengthError = checkFieldLengths($body)) {
        respond(['error' => $lengthError], 400);
    }

    if (isset($body['student_id']) && trim($body['student_id']) !== $current['student_id']) {
        $dupCheck = $pdo->prepare('SELECT id FROM users WHERE student_id = :sid AND id != :id');
        $dupCheck->execute(['sid' => trim($body['student_id']), 'id' => $id]);
        if ($dupCheck->fetch()) {
            respond(['error' => 'That Student ID is already registered.'], 409);
        }
    }

    foreach ($map as $key => $column) {
        if (array_key_exists($key, $body)) {
            $fields[] = "$column = :$column";
            $values[$column] = is_string($body[$key]) ? trim($body[$key]) : $body[$key];
        }
    }

    if (array_key_exists('hasVoted', $body)) {
        $fields[] = 'has_voted = :has_voted';
        $values['has_voted'] = $body['hasVoted'] ? 1 : 0;
    }

    if (!empty($body['password'])) {
        $password = (string) $body['password'];
        if (strlen($password) < 6) {
            respond(['error' => 'Password should be at least 6 characters.'], 400);
        }
        $fields[] = 'password = :password';
        $values['password'] = password_hash($password, PASSWORD_DEFAULT);
        // Setting a password activates the account, unless the admin is
        // explicitly suspending it in the same request.
        if (!isset($body['status'])) {
            $fields[] = 'registration_status = :auto_status';
            $values['auto_status'] = 'active';
        }
    }

    if (empty($fields)) {
        respond(['error' => 'No fields to update.'], 400);
    }

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($values);

    respond(['success' => true]);
}

// ---------- DELETE: remove a student ----------
if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        respond(['error' => 'Invalid student ID.'], 400);
    }

    // votes.user_id has ON DELETE CASCADE, so their votes go with them.
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute(['id' => $id]);

    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);