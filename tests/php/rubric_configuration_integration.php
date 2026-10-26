<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Evaluation/RubricConfigurationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$userIds = [];
$createdRoleIds = [];
$programId = null;
$periodId = null;
$applicationId = null;
$assignmentId = null;
$rubricIds = [];

function rubricTestEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
{
    $find = $db->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
    $find->bind_param('s', $key);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if (is_array($row)) return (int) $row['role_id'];
    $insert = $db->prepare('INSERT INTO roles (role_key, display_name) VALUES (?, ?)');
    $insert->bind_param('ss', $key, $label);
    $insert->execute();
    $id = (int) $db->insert_id;
    $insert->close();
    $createdRoleIds[] = $id;
    return $id;
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
    $roles = [
        'coordinator' => rubricTestEnsureRole($db, 'coordinator', 'Coordinator', $createdRoleIds),
        'applicant' => rubricTestEnsureRole($db, 'applicant', 'Applicant', $createdRoleIds),
        'evaluator' => rubricTestEnsureRole($db, 'evaluator', 'Evaluator', $createdRoleIds),
    ];

    foreach (array_keys($roles) as $kind) {
        $email = $kind . '-rubric-' . $suffix . '@example.invalid';
        $passwordHash = password_hash('rubric-test-password', PASSWORD_DEFAULT);
        $givenName = ucfirst($kind);
        $familyName = 'RubricTest';
        $insert = $db->prepare(
            "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $insert->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insert->execute();
        $userIds[$kind] = (int) $db->insert_id;
        $insert->close();

        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userIds[$kind], $roles[$kind]);
        $assignRole->execute();
        $assignRole->close();
    }

    $programCode = 'RUBRIC-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Rubric configuration integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $service = new RubricConfigurationService();
    $criteria = [
        ['criterion_code' => 'PORTFOLIO', 'criterion_name' => 'Portfolio evidence', 'description' => 'Evidence quality', 'max_score' => '10.00', 'weight' => '2.0000', 'sort_order' => 0],
        ['criterion_code' => 'EXPERIENCE', 'criterion_name' => 'Relevant experience', 'description' => 'Experience alignment', 'max_score' => '20', 'weight' => '1', 'sort_order' => 1],
    ];
    $first = $service->createVersion($db, $userIds['coordinator'], $programId, 'ETEEAP Portfolio Rubric', $criteria);
    $rubricIds[] = $first['rubric_id'];
    if ($first['version_number'] !== 1 || $first['criterion_count'] !== 2) {
        throw new RuntimeException('Initial rubric version was not created as expected.');
    }

    $second = $service->createVersion($db, $userIds['coordinator'], $programId, 'ETEEAP Portfolio Rubric', $criteria);
    $rubricIds[] = $second['rubric_id'];
    if ($second['version_number'] !== 2 || $second['criterion_count'] !== 2) {
        throw new RuntimeException('Second rubric version was not created as expected.');
    }

    $activeQuery = $db->prepare(
        'SELECT rubric_id FROM evaluation_rubrics WHERE program_id = ? AND is_active = TRUE'
    );
    $activeQuery->bind_param('i', $programId);
    $activeQuery->execute();
    $activeResult = $activeQuery->get_result();
    $activeIds = [];
    while ($row = $activeResult->fetch_assoc()) $activeIds[] = (int) $row['rubric_id'];
    $activeQuery->close();
    if ($activeIds !== [$second['rubric_id']]) {
        throw new RuntimeException('Rubric versioning did not preserve exactly one active version.');
    }

    try {
        $service->createVersion($db, $userIds['coordinator'], $programId, 'Invalid rubric', [
            ['criterion_code' => 'DUPLICATE', 'criterion_name' => 'First', 'max_score' => '10', 'weight' => '1'],
            ['criterion_code' => 'duplicate', 'criterion_name' => 'Second', 'max_score' => '10', 'weight' => '1'],
        ]);
        throw new RuntimeException('Duplicate criterion codes were accepted.');
    } catch (InvalidArgumentException) {
        // Criterion codes must be unique within the new version.
    }

    $periodName = 'Rubric period ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = (int) $db->insert_id;
    $period->close();

    $applicationNumber = 'RUBRIC-' . strtoupper(substr($suffix, 0, 8)) . '-ACTIVE';
    $status = 'under_evaluation';
    $submittedAt = date('Y-m-d H:i:s');
    $application = $db->prepare(
        'INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $application->bind_param('siiiss', $applicationNumber, $userIds['applicant'], $programId, $periodId, $status, $submittedAt);
    $application->execute();
    $applicationId = (int) $db->insert_id;
    $application->close();

    $assignmentStatus = 'assigned';
    $assignment = $db->prepare(
        'INSERT INTO evaluator_assignments
            (application_id, evaluator_user_id, assigned_by, assignment_status)
         VALUES (?, ?, ?, ?)'
    );
    $assignment->bind_param('iiis', $applicationId, $userIds['evaluator'], $userIds['coordinator'], $assignmentStatus);
    $assignment->execute();
    $assignmentId = (int) $db->insert_id;
    $assignment->close();

    try {
        $service->createVersion($db, $userIds['coordinator'], $programId, 'Unsafe rubric change', $criteria);
        throw new RuntimeException('Rubric changed while an application was under evaluation.');
    } catch (DomainException) {
        // Preserve the rubric version associated with all in-progress evaluation work.
    }

    $activeAfterBlock = $db->prepare(
        'SELECT rubric_id FROM evaluation_rubrics WHERE program_id = ? AND is_active = TRUE'
    );
    $activeAfterBlock->bind_param('i', $programId);
    $activeAfterBlock->execute();
    $activeRow = $activeAfterBlock->get_result()->fetch_assoc();
    $activeAfterBlock->close();
    if ((int) ($activeRow['rubric_id'] ?? 0) !== $second['rubric_id']) {
        throw new RuntimeException('Blocked rubric update changed the active version.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'evaluation_rubric.version_created'
           AND entity_type = 'evaluation_rubric' AND entity_id IN (?, ?)"
    );
    $audit->bind_param('iii', $userIds['coordinator'], $rubricIds[0], $rubricIds[1]);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 2) {
        throw new RuntimeException('Rubric version creation did not write audit events.');
    }

    fwrite(STDOUT, "PASS: rubric configuration (immutable versions, exactly one active rubric, duplicate-code rejection, in-progress protection and audit).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: rubric configuration integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        if ($assignmentId !== null) {
            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE evaluator_assignment_id = ?');
            $delete->bind_param('i', $assignmentId);
            $delete->execute();
            $delete->close();
        }
        if ($applicationId !== null) {
            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }
        if (isset($userIds['coordinator'])) {
            $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
            $delete->bind_param('i', $userIds['coordinator']);
            $delete->execute();
            $delete->close();
        }
        foreach ($rubricIds as $rubricId) {
            $delete = $db->prepare('DELETE FROM rubric_criteria WHERE rubric_id = ?');
            $delete->bind_param('i', $rubricId);
            $delete->execute();
            $delete->close();
            $delete = $db->prepare('DELETE FROM evaluation_rubrics WHERE rubric_id = ?');
            $delete->bind_param('i', $rubricId);
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
        if ($periodId !== null) {
            $delete = $db->prepare('DELETE FROM application_periods WHERE period_id = ?');
            $delete->bind_param('i', $periodId);
            $delete->execute();
            $delete->close();
        }
        if ($programId !== null) {
            $delete = $db->prepare('DELETE FROM academic_programs WHERE program_id = ?');
            $delete->bind_param('i', $programId);
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
exit($exitCode);
