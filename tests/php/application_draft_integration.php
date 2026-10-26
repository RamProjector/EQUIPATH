<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/ApplicationDraftService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$userId = null;
$programId = null;
$closedProgramId = null;
$periodId = null;
$closedPeriodId = null;
$applicationId = null;

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    $suffix = bin2hex(random_bytes(8));

    $email = 'draft-' . $suffix . '@example.invalid';
    $passwordHash = password_hash('test-only-password', PASSWORD_DEFAULT);
    $givenName = 'Draft';
    $familyName = 'IntegrationTest';
    $user = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $user->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
    $user->execute();
    $userId = (int) $db->insert_id;
    $user->close();

    $role = $db->prepare("SELECT role_id FROM roles WHERE role_key = 'applicant' LIMIT 1");
    $role->execute();
    $roleRow = $role->get_result()->fetch_assoc();
    $role->close();
    if (!is_array($roleRow)) {
        throw new RuntimeException('Applicant role is missing from test database.');
    }
    $roleId = (int) $roleRow['role_id'];
    $userRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    $userRole->bind_param('ii', $userId, $roleId);
    $userRole->execute();
    $userRole->close();

    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $programCode = 'DRAFT-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Draft creation integration test';
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $closedProgram = $db->prepare(
        'INSERT INTO academic_programs (program_code, program_name, is_active) VALUES (?, ?, FALSE)'
    );
    $closedProgramCode = 'DRAFTX-' . strtoupper(substr($suffix, 0, 7));
    $closedProgramName = 'Inactive draft test program';
    $closedProgram->bind_param('ss', $closedProgramCode, $closedProgramName);
    $closedProgram->execute();
    $closedProgramId = (int) $db->insert_id;
    $closedProgram->close();

    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $periodName = 'Open draft period ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 3600);
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = (int) $db->insert_id;
    $period->close();

    $closedPeriod = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'closed')"
    );
    $closedPeriodName = 'Closed draft period ' . $suffix;
    $closedOpensAt = date('Y-m-d H:i:s', time() - 7200);
    $closedClosesAt = date('Y-m-d H:i:s', time() - 3600);
    $closedPeriod->bind_param('sss', $closedPeriodName, $closedOpensAt, $closedClosesAt);
    $closedPeriod->execute();
    $closedPeriodId = (int) $db->insert_id;
    $closedPeriod->close();

    $service = new ApplicationDraftService();
    $application = $service->create($db, $userId, $programId, $periodId);
    $applicationId = $application['application_id'];

    if ($application['application_status'] !== 'draft' ||
        $application['program_id'] !== $programId ||
        $application['period_id'] !== $periodId ||
        !preg_match('/^EQ-[0-9]{8}-[A-F0-9]{16}$/', $application['application_number'])) {
        throw new RuntimeException('Created draft response failed its contract.');
    }

    $stored = $db->prepare(
        'SELECT applicant_user_id, application_status FROM applications WHERE application_id = ?'
    );
    $stored->bind_param('i', $applicationId);
    $stored->execute();
    $storedRow = $stored->get_result()->fetch_assoc();
    $stored->close();
    if (!is_array($storedRow) || (int) $storedRow['applicant_user_id'] !== $userId || $storedRow['application_status'] !== 'draft') {
        throw new RuntimeException('Draft was not persisted to the expected owner and status.');
    }

    $history = $db->prepare(
        "SELECT COUNT(*) AS total FROM application_status_history
         WHERE application_id = ? AND changed_by = ? AND to_status = 'draft'"
    );
    $history->bind_param('ii', $applicationId, $userId);
    $history->execute();
    $historyCount = (int) $history->get_result()->fetch_assoc()['total'];
    $history->close();
    if ($historyCount !== 1) {
        throw new RuntimeException('Draft creation did not write exactly one initial status history record.');
    }

    foreach ([[$programId, $closedPeriodId], [$closedProgramId, $periodId]] as [$badProgramId, $badPeriodId]) {
        try {
            $service->create($db, $userId, $badProgramId, $badPeriodId);
            throw new RuntimeException('Service accepted an inactive program or closed period.');
        } catch (DomainException) {
            // Expected: invalid program/period combinations are rejected.
        }
    }

    fwrite(STDOUT, "PASS: application draft service (ownership, generated number, initial history, inactive program and closed period rejection).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: application draft integration — ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($db instanceof mysqli) {
        if ($applicationId !== null) {
            $delete = $db->prepare('DELETE FROM application_status_history WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }
        foreach ([$programId, $closedProgramId] as $id) {
            if ($id !== null) {
                $delete = $db->prepare('DELETE FROM academic_programs WHERE program_id = ?');
                $delete->bind_param('i', $id);
                $delete->execute();
                $delete->close();
            }
        }
        foreach ([$periodId, $closedPeriodId] as $id) {
            if ($id !== null) {
                $delete = $db->prepare('DELETE FROM application_periods WHERE period_id = ?');
                $delete->bind_param('i', $id);
                $delete->execute();
                $delete->close();
            }
        }
        if ($userId !== null) {
            $delete = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
            $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }
        $db->close();
    }
}
