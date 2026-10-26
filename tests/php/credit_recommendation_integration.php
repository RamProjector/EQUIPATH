<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/CreditRecommendationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$createdRoleIds = [];
$programId = null;
$periodId = null;
$applicationIds = [];
$assignmentIds = [];

function creditEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
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
    $roleId = (int) $db->insert_id;
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
    $evaluatorRoleId = creditEnsureRole($db, 'evaluator', 'Evaluator', $createdRoleIds);
    $coordinatorRoleId = creditEnsureRole($db, 'coordinator', 'Coordinator', $createdRoleIds);
    $applicantRoleId = creditEnsureRole($db, 'applicant', 'Applicant', $createdRoleIds);

    $passwordHash = password_hash('credit-recommendation-test-password', PASSWORD_DEFAULT);
    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    foreach (['applicant', 'evaluator', 'other-evaluator', 'coordinator'] as $kind) {
        $email = $kind . '-credit-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'CreditTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = (int) $db->insert_id;
    }
    $insertUser->close();

    foreach ([
        [$userIds['applicant'], $applicantRoleId],
        [$userIds['evaluator'], $evaluatorRoleId],
        [$userIds['other-evaluator'], $evaluatorRoleId],
        [$userIds['coordinator'], $coordinatorRoleId],
    ] as [$userId, $roleId]) {
        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userId, $roleId);
        $assignRole->execute();
        $assignRole->close();
    }

    $programCode = 'CREDIT-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Credit recommendation integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $periodName = 'Credit period ' . $suffix;
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

    foreach (['valid', 'not-submitted'] as $kind) {
        $applicationNumber = 'CREDIT-' . strtoupper(substr($suffix, 0, 8)) . '-' . strtoupper($kind);
        $status = 'under_evaluation';
        $submittedAt = date('Y-m-d H:i:s');
        $application = $db->prepare(
            'INSERT INTO applications
                (application_number, applicant_user_id, program_id, period_id, application_status, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $application->bind_param('siiiss', $applicationNumber, $userIds['applicant'], $programId, $periodId, $status, $submittedAt);
        $application->execute();
        $applicationIds[$kind] = (int) $db->insert_id;
        $application->close();

        $assignmentStatus = $kind === 'valid' ? 'submitted' : 'in_progress';
        $assignmentSubmittedAt = $kind === 'valid' ? date('Y-m-d H:i:s') : null;
        $assignment = $db->prepare(
            'INSERT INTO evaluator_assignments
                (application_id, evaluator_user_id, assigned_by, assignment_status, submitted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $assignment->bind_param(
            'iiiss',
            $applicationIds[$kind],
            $userIds['evaluator'],
            $userIds['coordinator'],
            $assignmentStatus,
            $assignmentSubmittedAt
        );
        $assignment->execute();
        $assignmentIds[$kind] = (int) $db->insert_id;
        $assignment->close();
    }

    $service = new CreditRecommendationService();
    $result = $service->submit(
        $db,
        $userIds['evaluator'],
        $assignmentIds['valid'],
        [
            [
                'course_code' => 'cs101',
                'course_title' => 'Foundations of Computing',
                'recommended_credits' => '3.00',
                'rationale' => 'Portfolio evidence demonstrates equivalent learning outcomes.',
            ],
            [
                'course_code' => 'MATH-1',
                'course_title' => 'Applied Mathematics',
                'recommended_credits' => 2.5,
                'rationale' => 'Work history and submitted evidence support the recommendation.',
            ],
        ]
    );
    if ($result['count'] !== 2 || count($result['recommendation_ids']) !== 2 ||
        $result['application_id'] !== $applicationIds['valid']) {
        throw new RuntimeException('Credit recommendation submission did not return two persisted recommendations.');
    }

    $stored = $db->prepare(
        'SELECT course_code, recommended_credits
         FROM credit_recommendations
         WHERE evaluator_assignment_id = ? ORDER BY course_code'
    );
    $stored->bind_param('i', $assignmentIds['valid']);
    $stored->execute();
    $storedResult = $stored->get_result();
    $storedRows = [];
    while ($row = $storedResult->fetch_assoc()) {
        $storedRows[] = $row;
    }
    $stored->close();
    if (count($storedRows) !== 2 ||
        $storedRows[0]['course_code'] !== 'CS101' ||
        $storedRows[0]['recommended_credits'] !== '3.00') {
        throw new RuntimeException('Credit recommendation course code normalization or credit persistence failed.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'credit_recommendations.submitted'
           AND entity_type = 'evaluator_assignment' AND entity_id = ? AND outcome = 'success'"
    );
    $audit->bind_param('ii', $userIds['evaluator'], $assignmentIds['valid']);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 1) {
        throw new RuntimeException('Credit recommendation submission did not write one audit event.');
    }

    $notice = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'credit_recommendations'"
    );
    $notice->bind_param('ii', $userIds['coordinator'], $applicationIds['valid']);
    $notice->execute();
    $noticeCount = (int) $notice->get_result()->fetch_assoc()['total'];
    $notice->close();
    if ($noticeCount !== 1) {
        throw new RuntimeException('Credit recommendation submission did not notify the coordinator.');
    }

    try {
        $service->submit(
            $db,
            $userIds['evaluator'],
            $assignmentIds['valid'],
            [[
                'course_code' => 'cs101',
                'course_title' => 'Duplicate course',
                'recommended_credits' => 3,
                'rationale' => 'This must be rejected.',
            ]]
        );
        throw new RuntimeException('Service allowed a duplicate course recommendation.');
    } catch (DomainException) {
        // Existing course codes cannot be duplicated for the same assignment.
    }

    try {
        $service->submit(
            $db,
            $userIds['evaluator'],
            $assignmentIds['valid'],
            [
                ['course_code' => 'BIO1', 'course_title' => 'Biology', 'recommended_credits' => 3, 'rationale' => 'Valid rationale'],
                ['course_code' => 'bio1', 'course_title' => 'Biology duplicate', 'recommended_credits' => 2, 'rationale' => 'Duplicate in one request'],
            ]
        );
        throw new RuntimeException('Service allowed duplicate course codes within one submission.');
    } catch (InvalidArgumentException) {
        // Duplicate codes in a request must be rejected before writes.
    }

    try {
        $service->submit(
            $db,
            $userIds['other-evaluator'],
            $assignmentIds['valid'],
            [[
                'course_code' => 'BIO2',
                'course_title' => 'Biology II',
                'recommended_credits' => 3,
                'rationale' => 'Not the assigned evaluator.',
            ]]
        );
        throw new RuntimeException('Service allowed a different evaluator to submit recommendations.');
    } catch (DomainException) {
        // Assignment ownership is mandatory.
    }

    try {
        $service->submit(
            $db,
            $userIds['evaluator'],
            $assignmentIds['not-submitted'],
            [[
                'course_code' => 'BIO3',
                'course_title' => 'Biology III',
                'recommended_credits' => 3,
                'rationale' => 'Evaluation is not submitted yet.',
            ]]
        );
        throw new RuntimeException('Service allowed recommendations before evaluation submission.');
    } catch (DomainException) {
        // Recommendations are only allowed after evaluator submission.
    }

    $rejectedCountQuery = $db->prepare(
        'SELECT COUNT(*) AS total FROM credit_recommendations WHERE evaluator_assignment_id = ?'
    );
    $rejectedCountQuery->bind_param('i', $assignmentIds['not-submitted']);
    $rejectedCountQuery->execute();
    $rejectedCount = (int) $rejectedCountQuery->get_result()->fetch_assoc()['total'];
    $rejectedCountQuery->close();
    if ($rejectedCount !== 0) {
        throw new RuntimeException('Rejected recommendation attempt left database rows behind.');
    }

    fwrite(STDOUT, "PASS: credit recommendations (submitted assignment ownership, normalization, duplicates, audit, notifications and rollback).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: credit recommendation integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($assignmentIds as $assignmentId) {
            $delete = $db->prepare('DELETE FROM credit_recommendations WHERE evaluator_assignment_id = ?');
            $delete->bind_param('i', $assignmentId);
            $delete->execute();
            $delete->close();
        }
        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM notifications WHERE related_application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }
        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }
        foreach ($assignmentIds as $assignmentId) {
            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE evaluator_assignment_id = ?');
            $delete->bind_param('i', $assignmentId);
            $delete->execute();
            $delete->close();
        }
        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM application_status_history WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
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
        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM notifications WHERE recipient_user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

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
exit($exitCode);
