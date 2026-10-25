<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Security/AccessControl.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$createdRoleIds = [];
$userIds = [];
$applicationIds = [];
$programId = null;
$periodId = null;

function insertId(mysqli $db): int
{
    return (int) $db->insert_id;
}

function expectDenied(callable $action, string $message): void
{
    try {
        $action();
    } catch (AuthorizationDeniedException | AuthenticationRequiredException) {
        return;
    }
    throw new RuntimeException($message);
}

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );

    $suffix = bin2hex(random_bytes(8));
    $roleIds = [];
    foreach ([
        'applicant' => 'Applicant',
        'evaluator' => 'Evaluator',
        'administrator' => 'Administrator',
    ] as $key => $label) {
        $find = $db->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
        $find->bind_param('s', $key);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if (is_array($row)) {
            $roleIds[$key] = (int) $row['role_id'];
        } else {
            $add = $db->prepare('INSERT INTO roles (role_key, display_name) VALUES (?, ?)');
            $add->bind_param('ss', $key, $label);
            $add->execute();
            $roleIds[$key] = insertId($db);
            $createdRoleIds[] = $roleIds[$key];
            $add->close();
        }
    }

    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $passwordHash = password_hash('not-used-in-this-test', PASSWORD_DEFAULT);
    foreach (['applicant', 'other', 'evaluator', 'administrator'] as $kind) {
        $email = $kind . '-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'AuthorizationTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = insertId($db);
    }
    $insertUser->close();

    foreach (['applicant' => 'applicant', 'evaluator' => 'evaluator', 'administrator' => 'administrator'] as $kind => $roleKey) {
        $addRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $addRole->bind_param('ii', $userIds[$kind], $roleIds[$roleKey]);
        $addRole->execute();
        $addRole->close();
    }

    $programCode = 'AUTHZ-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Authorization integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = insertId($db);
    $program->close();

    $periodName = 'Authorization test ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = insertId($db);
    $period->close();

    foreach (['applicant', 'other'] as $kind) {
        $applicationNumber = 'AUTHZ-' . strtoupper(substr($suffix, 0, 8)) . '-' . strtoupper($kind);
        $app = $db->prepare(
            'INSERT INTO applications (application_number, applicant_user_id, program_id, period_id)
             VALUES (?, ?, ?, ?)'
        );
        $app->bind_param('siii', $applicationNumber, $userIds[$kind], $programId, $periodId);
        $app->execute();
        $applicationIds[$kind] = insertId($db);
        $app->close();
    }

    $assignmentStatus = 'assigned';
    $assignment = $db->prepare(
        'INSERT INTO evaluator_assignments (application_id, evaluator_user_id, assigned_by, assignment_status)
         VALUES (?, ?, ?, ?)'
    );
    $assignment->bind_param('iiis', $applicationIds['applicant'], $userIds['evaluator'], $userIds['administrator'], $assignmentStatus);
    $assignment->execute();
    $assignment->close();

    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException('Unable to start test session.');
    }
    $access = new AccessControl();

    $_SESSION['user_id'] = $userIds['applicant'];
    $access->requireApplicationAccess($db, $applicationIds['applicant']);
    expectDenied(
        fn() => $access->requireApplicationAccess($db, $applicationIds['other']),
        'Applicant accessed another applicant application.'
    );

    $_SESSION['user_id'] = $userIds['evaluator'];
    $access->requireApplicationAccess($db, $applicationIds['applicant']);
    expectDenied(
        fn() => $access->requireApplicationAccess($db, $applicationIds['other']),
        'Evaluator accessed an unassigned application.'
    );

    $_SESSION['user_id'] = $userIds['administrator'];
    expectDenied(
        fn() => $access->requireApplicationAccess($db, $applicationIds['applicant']),
        'Administrator received implicit application access.'
    );
    $access->requireApplicationAccess($db, $applicationIds['applicant'], ['administrator']);

    $_SESSION['user_id'] = $userIds['other'];
    expectDenied(
        fn() => $access->requireAnyRole($db, ['applicant']),
        'User without a role was authorized.'
    );

    fwrite(STDOUT, "PASS: authorization integration checks (applicant ownership, evaluator assignment, explicit staff allow-list, no implicit administrator access).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: authorization integration checks — ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    if ($db instanceof mysqli) {
        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }

        if ($programId !== null) {
            $delete = $db->prepare('DELETE FROM academic_programs WHERE program_id = ?');
            $delete->bind_param('i', $programId);
            $delete->execute();
            $delete->close();
        }
        if ($periodId !== null) {
            $delete = $db->prepare('DELETE FROM application_periods WHERE period_id = ?');
            $delete->bind_param('i', $periodId);
            $delete->execute();
            $delete->close();
        }

        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }

        foreach ($createdRoleIds as $roleId) {
            $delete = $db->prepare('DELETE FROM roles WHERE role_id = ?');
            $delete->bind_param('i', $roleId);
            $delete->execute();
            $delete->close();
        }

        $db->close();
    }
}
