<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/EvaluatorAssignmentService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$roleIdsCreated = [];
$programId = null;
$periodId = null;
$applicationIds = [];

function assignmentInsertId(mysqli $db): int
{
    return (int) $db->insert_id;
}

function assignmentEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
{
    $find = $db->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
    $find->bind_param('s', $key);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if (is_array($row)) {
        return (int) $row['role_id'];
    }

    $insert = $db->prepare('INSERT INTO roles (role_key, display_name) VALUES (?, ?)');
    $insert->bind_param('ss', $key, $label);
    $insert->execute();
    $roleId = assignmentInsertId($db);
    $insert->close();
    $createdRoleIds[] = $roleId;
    return $roleId;
}

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
    $coordinatorRoleId = assignmentEnsureRole($db, 'coordinator', 'Coordinator', $roleIdsCreated);
    $evaluatorRoleId = assignmentEnsureRole($db, 'evaluator', 'Evaluator', $roleIdsCreated);

    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $passwordHash = password_hash('assignment-test-password', PASSWORD_DEFAULT);
    foreach (['applicant', 'coordinator', 'evaluator', 'other'] as $kind) {
        $email = $kind . '-assignment-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'AssignmentTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = assignmentInsertId($db);
    }
    $insertUser->close();

    foreach ([
        [$userIds['coordinator'], $coordinatorRoleId],
        [$userIds['evaluator'], $evaluatorRoleId],
    ] as [$userId, $roleId]) {
        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userId, $roleId);
        $assignRole->execute();
        $assignRole->close();
    }

    $programCode = 'ASSIGN-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Evaluator assignment integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = assignmentInsertId($db);
    $program->close();

    $periodName = 'Assignment period ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = assignmentInsertId($db);
    $period->close();

    foreach (['eligible', 'draft'] as $status) {
        $applicationNumber = 'ASSIGN-' . strtoupper(substr($suffix, 0, 8)) . '-' . strtoupper($status);
        $submittedAt = $status === 'draft' ? null : date('Y-m-d H:i:s');
        $application = $db->prepare(
            'INSERT INTO applications
                (application_number, applicant_user_id, program_id, period_id, application_status, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $application->bind_param(
            'siiiss',
            $applicationNumber,
            $userIds['applicant'],
            $programId,
            $periodId,
            $status,
            $submittedAt
        );
        $application->execute();
        $applicationIds[$status] = assignmentInsertId($db);
        $application->close();
    }

    $service = new EvaluatorAssignmentService();
    $result = $service->assign(
        $db,
        $userIds['coordinator'],
        $applicationIds['eligible'],
        $userIds['evaluator']
    );
    if ($result['application_status'] !== 'under_evaluation' ||
        $result['evaluator_user_id'] !== $userIds['evaluator'] ||
        $result['application_id'] !== $applicationIds['eligible']) {
        throw new RuntimeException('Assignment response did not reflect the expected evaluation transition.');
    }

    $assignmentId = $result['evaluator_assignment_id'];
    $assignment = $db->prepare(
        "SELECT assignment_status, assigned_by FROM evaluator_assignments
         WHERE evaluator_assignment_id = ? AND application_id = ? AND evaluator_user_id = ?"
    );
    $assignment->bind_param('iii', $assignmentId, $applicationIds['eligible'], $userIds['evaluator']);
    $assignment->execute();
    $assignmentRow = $assignment->get_result()->fetch_assoc();
    $assignment->close();
    if (!is_array($assignmentRow) ||
        $assignmentRow['assignment_status'] !== 'assigned' ||
        (int) $assignmentRow['assigned_by'] !== $userIds['coordinator']) {
        throw new RuntimeException('Assignment record was not persisted with the expected actor and status.');
    }

    $app = $db->prepare('SELECT application_status FROM applications WHERE application_id = ?');
    $app->bind_param('i', $applicationIds['eligible']);
    $app->execute();
    $appStatus = $app->get_result()->fetch_assoc()['application_status'] ?? null;
    $app->close();
    if ($appStatus !== 'under_evaluation') {
        throw new RuntimeException('Eligible application did not move to under_evaluation.');
    }

    $history = $db->prepare(
        "SELECT COUNT(*) AS total FROM application_status_history
         WHERE application_id = ? AND changed_by = ? AND from_status = 'eligible'
           AND to_status = 'under_evaluation'"
    );
    $history->bind_param('ii', $applicationIds['eligible'], $userIds['coordinator']);
    $history->execute();
    $historyCount = (int) $history->get_result()->fetch_assoc()['total'];
    $history->close();
    if ($historyCount !== 1) {
        throw new RuntimeException('Assignment did not create the expected application status history.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'evaluator.assigned'
           AND entity_type = 'application' AND entity_id = ? AND outcome = 'success'"
    );
    $audit->bind_param('ii', $userIds['coordinator'], $applicationIds['eligible']);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 1) {
        throw new RuntimeException('Assignment did not create the expected audit event.');
    }

    $evaluatorNotice = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'evaluator_assignment'"
    );
    $evaluatorNotice->bind_param('ii', $userIds['evaluator'], $applicationIds['eligible']);
    $evaluatorNotice->execute();
    $evaluatorNoticeCount = (int) $evaluatorNotice->get_result()->fetch_assoc()['total'];
    $evaluatorNotice->close();
    if ($evaluatorNoticeCount !== 1) {
        throw new RuntimeException('Assigned evaluator was not notified.');
    }

    $applicantNotice = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'application_status'"
    );
    $applicantNotice->bind_param('ii', $userIds['applicant'], $applicationIds['eligible']);
    $applicantNotice->execute();
    $applicantNoticeCount = (int) $applicantNotice->get_result()->fetch_assoc()['total'];
    $applicantNotice->close();
    if ($applicantNoticeCount !== 1) {
        throw new RuntimeException('Applicant was not notified that evaluation started.');
    }

    foreach ([
        [$applicationIds['eligible'], $userIds['evaluator'], 'duplicate assignment'],
        [$applicationIds['draft'], $userIds['evaluator'], 'draft application'],
        [$applicationIds['eligible'], $userIds['other'], 'account without evaluator role'],
        [$applicationIds['eligible'], $userIds['applicant'], 'self-assignment'],
    ] as [$applicationId, $targetUserId, $label]) {
        try {
            $service->assign($db, $userIds['coordinator'], $applicationId, $targetUserId);
            throw new RuntimeException('Service accepted ' . $label . '.');
        } catch (DomainException) {
            // Expected: invalid assignments are rejected without partial writes.
        }
    }

    $draftEffects = $db->prepare(
        'SELECT COUNT(*) AS total FROM evaluator_assignments WHERE application_id = ?'
    );
    $draftEffects->bind_param('i', $applicationIds['draft']);
    $draftEffects->execute();
    $draftAssignmentCount = (int) $draftEffects->get_result()->fetch_assoc()['total'];
    $draftEffects->close();
    if ($draftAssignmentCount !== 0) {
        throw new RuntimeException('Rejected draft assignment left an assignment record.');
    }

    fwrite(STDOUT, "PASS: evaluator assignment service (role/status checks, assignment history, notifications, audit and duplicate/self-assignment rejection).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: evaluator assignment integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($applicationIds as $applicationId) {
            foreach ([
                'DELETE FROM evaluator_assignments WHERE application_id = ?',
                'DELETE FROM notifications WHERE related_application_id = ?',
                'DELETE FROM application_status_history WHERE application_id = ?',
            ] as $sql) {
                $delete = $db->prepare($sql);
                $delete->bind_param('i', $applicationId);
                $delete->execute();
                $delete->close();
            }
        }

        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM notifications WHERE recipient_user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }

        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }
        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
            $delete->bind_param('i', $userId);
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
        foreach ($roleIdsCreated as $roleId) {
            $delete = $db->prepare('DELETE FROM roles WHERE role_id = ?');
            $delete->bind_param('i', $roleId);
            $delete->execute();
            $delete->close();
        }
        $db->close();
    }
}

exit($exitCode);
