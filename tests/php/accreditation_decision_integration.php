<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Accreditation/AccreditationDecisionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$createdRoleIds = [];
$programId = null;
$periodId = null;
$applicationIds = [];
$assignmentIds = [];
$recommendationIds = [];

function decisionEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
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
    $approverRoleId = decisionEnsureRole($db, 'approver', 'Approver', $createdRoleIds);
    $coordinatorRoleId = decisionEnsureRole($db, 'coordinator', 'Coordinator', $createdRoleIds);
    $evaluatorRoleId = decisionEnsureRole($db, 'evaluator', 'Evaluator', $createdRoleIds);
    $applicantRoleId = decisionEnsureRole($db, 'applicant', 'Applicant', $createdRoleIds);

    $passwordHash = password_hash('accreditation-decision-test-password', PASSWORD_DEFAULT);
    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    foreach (['applicant', 'approver', 'coordinator', 'evaluator'] as $kind) {
        $email = $kind . '-decision-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'DecisionTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = (int) $db->insert_id;
    }
    $insertUser->close();

    foreach ([
        [$userIds['applicant'], $applicantRoleId],
        [$userIds['approver'], $approverRoleId],
        [$userIds['coordinator'], $coordinatorRoleId],
        [$userIds['evaluator'], $evaluatorRoleId],
    ] as [$userId, $roleId]) {
        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userId, $roleId);
        $assignRole->execute();
        $assignRole->close();
    }

    $programCode = 'DECISION-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Accreditation decision integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $periodName = 'Decision period ' . $suffix;
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

    foreach (['approval', 'revision'] as $kind) {
        $applicationNumber = 'DECISION-' . strtoupper(substr($suffix, 0, 8)) . '-' . strtoupper($kind);
        $status = 'awaiting_decision';
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

        $assignmentStatus = 'submitted';
        $assignmentSubmittedAt = date('Y-m-d H:i:s');
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

    foreach ([
        [$applicationIds['approval'], $assignmentIds['approval'], 'CS101', 'Foundations of Computing', '3.00'],
        [$applicationIds['approval'], $assignmentIds['approval'], 'MATH-1', 'Applied Mathematics', '2.50'],
        [$applicationIds['revision'], $assignmentIds['revision'], 'BIO1', 'Biology Fundamentals', '3.00'],
    ] as [$appId, $assignmentId, $courseCode, $courseTitle, $credits]) {
        $rationale = 'Integration test recommendation rationale.';
        $recommendation = $db->prepare(
            'INSERT INTO credit_recommendations
                (application_id, evaluator_assignment_id, course_code, course_title, recommended_credits, rationale)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $recommendation->bind_param('iissss', $appId, $assignmentId, $courseCode, $courseTitle, $credits, $rationale);
        $recommendation->execute();
        $recommendationIds[] = (int) $db->insert_id;
        $recommendation->close();
    }

    $service = new AccreditationDecisionService();
    $approved = $service->decide(
        $db,
        $userIds['approver'],
        $applicationIds['approval'],
        'approved',
        'Portfolio and assessment requirements have been reviewed.',
        null,
        [
            ['course_code' => 'cs101', 'course_title' => 'Foundations of Computing', 'approved_credits' => '3.00'],
            ['course_code' => 'MATH-1', 'course_title' => 'Applied Mathematics', 'approved_credits' => '2.00'],
        ]
    );
    if ($approved['decision'] !== 'approved' ||
        $approved['application_status'] !== 'approved' ||
        $approved['approved_credit_count'] !== 2 ||
        $approved['supersedes_decision_id'] !== null) {
        throw new RuntimeException('Approval did not persist the expected decision and approved credits.');
    }

    $approvedDecisionId = $approved['accreditation_decision_id'];
    $creditCountQuery = $db->prepare(
        'SELECT COUNT(*) AS total FROM accredited_credits WHERE accreditation_decision_id = ?'
    );
    $creditCountQuery->bind_param('i', $approvedDecisionId);
    $creditCountQuery->execute();
    $approvedCreditCount = (int) $creditCountQuery->get_result()->fetch_assoc()['total'];
    $creditCountQuery->close();
    if ($approvedCreditCount !== 2) {
        throw new RuntimeException('Approved credit rows were not persisted with the decision.');
    }

    try {
        $service->decide(
            $db,
            $userIds['approver'],
            $applicationIds['approval'],
            'approved',
            'Attempt to approve more than recommended.',
            $approvedDecisionId,
            [['course_code' => 'CS101', 'course_title' => 'Foundations of Computing', 'approved_credits' => 4]]
        );
        throw new RuntimeException('Service allowed approved credits above the submitted recommendation.');
    } catch (DomainException) {
        // Approved credits cannot exceed the matching submitted recommendation.
    }

    try {
        $service->decide(
            $db,
            $userIds['approver'],
            $applicationIds['approval'],
            'rejected',
            'A superseding decision must be explicit.',
            null,
            []
        );
        throw new RuntimeException('Service allowed a new decision without superseding the current decision.');
    } catch (DomainException) {
        // Current decisions are immutable unless explicitly superseded.
    }

    $superseded = $service->decide(
        $db,
        $userIds['approver'],
        $applicationIds['approval'],
        'rejected',
        'A reviewed correction supersedes the earlier decision.',
        $approvedDecisionId,
        []
    );
    if ($superseded['decision'] !== 'rejected' ||
        $superseded['application_status'] !== 'rejected' ||
        $superseded['supersedes_decision_id'] !== $approvedDecisionId) {
        throw new RuntimeException('Superseding decision did not preserve the prior decision link and new status.');
    }

    $revision = $service->decide(
        $db,
        $userIds['approver'],
        $applicationIds['revision'],
        'returned_for_revision',
        'Additional documentation is required.',
        null,
        []
    );
    if ($revision['application_status'] !== 'needs_correction') {
        throw new RuntimeException('Returned-for-revision decision did not move application to needs_correction.');
    }

    $currentDecisionQuery = $db->prepare(
        'SELECT COUNT(*) AS total FROM accreditation_decisions
         WHERE application_id = ? AND supersedes_decision_id IS NULL'
    );
    $currentDecisionQuery->bind_param('i', $applicationIds['approval']);
    $currentDecisionQuery->execute();
    $decisionCount = (int) $currentDecisionQuery->get_result()->fetch_assoc()['total'];
    $currentDecisionQuery->close();
    if ($decisionCount !== 1) {
        throw new RuntimeException('Expected an immutable decision history with one first decision and one superseding decision.');
    }

    $historyQuery = $db->prepare(
        "SELECT COUNT(*) AS total FROM application_status_history
         WHERE application_id = ? AND changed_by = ? AND from_status = 'awaiting_decision' AND to_status = 'approved'"
    );
    $historyQuery->bind_param('ii', $applicationIds['approval'], $userIds['approver']);
    $historyQuery->execute();
    $historyCount = (int) $historyQuery->get_result()->fetch_assoc()['total'];
    $historyQuery->close();
    if ($historyCount !== 1) {
        throw new RuntimeException('Application status history did not record approval.');
    }

    $notifications = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND notification_type = 'accreditation_decision'
           AND related_application_id IN (?, ?)"
    );
    $notifications->bind_param('iii', $userIds['applicant'], $applicationIds['approval'], $applicationIds['revision']);
    $notifications->execute();
    $notificationCount = (int) $notifications->get_result()->fetch_assoc()['total'];
    $notifications->close();
    if ($notificationCount !== 3) {
        throw new RuntimeException('Expected one applicant notification for each recorded decision.');
    }

    fwrite(STDOUT, "PASS: accreditation decisions (approver outcome, recommendation-bound credits, superseding history, status transitions, audit and applicant notifications).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: accreditation decision integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM accredited_credits WHERE accreditation_decision_id IN (SELECT accreditation_decision_id FROM accreditation_decisions WHERE application_id = ?)');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $decisionQuery = $db->prepare(
                'SELECT accreditation_decision_id FROM accreditation_decisions
                 WHERE application_id = ? ORDER BY accreditation_decision_id DESC'
            );
            $decisionQuery->bind_param('i', $applicationId);
            $decisionQuery->execute();
            $decisionResult = $decisionQuery->get_result();
            $decisionIds = [];
            while ($row = $decisionResult->fetch_assoc()) {
                $decisionIds[] = (int) $row['accreditation_decision_id'];
            }
            $decisionQuery->close();
            foreach ($decisionIds as $decisionId) {
                $delete = $db->prepare('DELETE FROM accreditation_decisions WHERE accreditation_decision_id = ?');
                $delete->bind_param('i', $decisionId);
                $delete->execute();
                $delete->close();
            }

            $delete = $db->prepare('DELETE FROM notifications WHERE related_application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM application_status_history WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM credit_recommendations WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
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
