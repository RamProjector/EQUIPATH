<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/EvaluatorScoringService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$createdRoleIds = [];
$programId = null;
$periodId = null;
$rubricId = null;
$criterionIds = [];
$applicationIds = [];
$assignmentIds = [];

function scoringEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
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
    $evaluatorRoleId = scoringEnsureRole($db, 'evaluator', 'Evaluator', $createdRoleIds);
    $coordinatorRoleId = scoringEnsureRole($db, 'coordinator', 'Coordinator', $createdRoleIds);

    $passwordHash = password_hash('rubric-scoring-test-password', PASSWORD_DEFAULT);
    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    foreach (['applicant', 'evaluator', 'other-evaluator', 'coordinator'] as $kind) {
        $email = $kind . '-scoring-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'ScoringTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = (int) $db->insert_id;
    }
    $insertUser->close();

    foreach ([
        [$userIds['evaluator'], $evaluatorRoleId],
        [$userIds['other-evaluator'], $evaluatorRoleId],
        [$userIds['coordinator'], $coordinatorRoleId],
    ] as [$userId, $roleId]) {
        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userId, $roleId);
        $assignRole->execute();
        $assignRole->close();
    }

    $programCode = 'SCORE-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Rubric scoring integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $periodName = 'Scoring period ' . $suffix;
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

    $rubricName = 'Integration rubric ' . $suffix;
    $version = 1;
    $active = 1;
    $rubric = $db->prepare(
        'INSERT INTO evaluation_rubrics (program_id, rubric_name, version_number, is_active)
         VALUES (?, ?, ?, ?)'
    );
    $rubric->bind_param('isii', $programId, $rubricName, $version, $active);
    $rubric->execute();
    $rubricId = (int) $db->insert_id;
    $rubric->close();

    foreach ([
        ['KNOWLEDGE', 'Relevant knowledge', 10.00, 1.0000, 1],
        ['EXPERIENCE', 'Relevant experience', 20.00, 2.0000, 2],
    ] as [$code, $name, $maxScore, $weight, $sortOrder]) {
        $criterion = $db->prepare(
            'INSERT INTO rubric_criteria
                (rubric_id, criterion_code, criterion_name, max_score, weight, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $criterion->bind_param('issddi', $rubricId, $code, $name, $maxScore, $weight, $sortOrder);
        $criterion->execute();
        $criterionIds[] = (int) $db->insert_id;
        $criterion->close();
    }

    foreach (['valid', 'invalid-score'] as $kind) {
        $applicationNumber = 'SCORE-' . strtoupper(substr($suffix, 0, 8)) . '-' . strtoupper($kind);
        $submittedAt = date('Y-m-d H:i:s');
        $status = 'under_evaluation';
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
        $applicationIds[$kind] = (int) $db->insert_id;
        $application->close();

        $assignmentStatus = 'assigned';
        $assignment = $db->prepare(
            'INSERT INTO evaluator_assignments
                (application_id, evaluator_user_id, assigned_by, assignment_status)
             VALUES (?, ?, ?, ?)'
        );
        $assignment->bind_param(
            'iiis',
            $applicationIds[$kind],
            $userIds['evaluator'],
            $userIds['coordinator'],
            $assignmentStatus
        );
        $assignment->execute();
        $assignmentIds[$kind] = (int) $db->insert_id;
        $assignment->close();
    }

    $service = new EvaluatorScoringService();
    $draft = $service->save(
        $db,
        $userIds['evaluator'],
        $assignmentIds['valid'],
        $rubricId,
        [['criterion_id' => $criterionIds[0], 'score' => 8, 'feedback' => 'Clear evidence']],
        false
    );
    if ($draft['assignment_status'] !== 'in_progress' ||
        $draft['criteria_scored'] !== 1 ||
        $draft['weighted_percentage'] !== null) {
        throw new RuntimeException('Draft scoring did not preserve the expected in-progress state.');
    }

    $submitted = $service->save(
        $db,
        $userIds['evaluator'],
        $assignmentIds['valid'],
        $rubricId,
        [
            ['criterion_id' => $criterionIds[0], 'score' => '8.00', 'feedback' => 'Clear evidence'],
            ['criterion_id' => $criterionIds[1], 'score' => 10, 'feedback' => 'Relevant experience demonstrated'],
        ],
        true
    );
    if ($submitted['assignment_status'] !== 'submitted' ||
        $submitted['criteria_scored'] !== 2 ||
        $submitted['weighted_percentage'] !== 60.0) {
        throw new RuntimeException('Submitted weighted rubric result was not calculated correctly.');
    }

    $savedScores = $db->prepare(
        'SELECT COUNT(*) AS total FROM evaluation_scores WHERE evaluator_assignment_id = ?'
    );
    $savedScores->bind_param('i', $assignmentIds['valid']);
    $savedScores->execute();
    $scoreCount = (int) $savedScores->get_result()->fetch_assoc()['total'];
    $savedScores->close();
    if ($scoreCount !== 2) {
        throw new RuntimeException('Expected exactly two saved criterion scores.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'evaluation.submitted'
           AND entity_type = 'evaluator_assignment' AND entity_id = ? AND outcome = 'success'"
    );
    $audit->bind_param('ii', $userIds['evaluator'], $assignmentIds['valid']);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 1) {
        throw new RuntimeException('Evaluation submission did not write one audit event.');
    }

    $notice = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'evaluation_submitted'"
    );
    $notice->bind_param('ii', $userIds['coordinator'], $applicationIds['valid']);
    $notice->execute();
    $noticeCount = (int) $notice->get_result()->fetch_assoc()['total'];
    $notice->close();
    if ($noticeCount !== 1) {
        throw new RuntimeException('Evaluation submission did not notify the coordinator.');
    }

    try {
        $service->save(
            $db,
            $userIds['evaluator'],
            $assignmentIds['valid'],
            $rubricId,
            [
                ['criterion_id' => $criterionIds[0], 'score' => 7, 'feedback' => 'Changed'],
                ['criterion_id' => $criterionIds[1], 'score' => 11, 'feedback' => 'Changed'],
            ],
            true
        );
        throw new RuntimeException('Service allowed edits after submission.');
    } catch (DomainException) {
        // Submitted assignments must be immutable.
    }

    try {
        $service->save(
            $db,
            $userIds['other-evaluator'],
            $assignmentIds['invalid-score'],
            $rubricId,
            [['criterion_id' => $criterionIds[0], 'score' => 8]],
            false
        );
        throw new RuntimeException('Service allowed a different evaluator to edit the assignment.');
    } catch (DomainException) {
        // Assignment ownership is checked inside the transaction.
    }

    try {
        $service->save(
            $db,
            $userIds['evaluator'],
            $assignmentIds['invalid-score'],
            $rubricId,
            [
                ['criterion_id' => $criterionIds[0], 'score' => 11],
                ['criterion_id' => $criterionIds[1], 'score' => 10],
            ],
            true
        );
        throw new RuntimeException('Service allowed a score above the criterion maximum.');
    } catch (InvalidArgumentException) {
        // Score maximum validation must reject the entire transaction.
    }

    $rejectedScores = $db->prepare(
        'SELECT COUNT(*) AS total FROM evaluation_scores WHERE evaluator_assignment_id = ?'
    );
    $rejectedScores->bind_param('i', $assignmentIds['invalid-score']);
    $rejectedScores->execute();
    $rejectedCount = (int) $rejectedScores->get_result()->fetch_assoc()['total'];
    $rejectedScores->close();
    if ($rejectedCount !== 0) {
        throw new RuntimeException('Rejected scoring attempt left partial score rows behind.');
    }

    try {
        $service->save(
            $db,
            $userIds['evaluator'],
            $assignmentIds['invalid-score'],
            $rubricId,
            [['criterion_id' => $criterionIds[0], 'score' => 8]],
            true
        );
        throw new RuntimeException('Service allowed submission without every rubric criterion.');
    } catch (InvalidArgumentException) {
        // Submission must be complete before assignment status can be finalized.
    }

    fwrite(STDOUT, "PASS: evaluator rubric scoring (draft, weighted submission, audit/notification, ownership, score bounds and submission locking).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: evaluator rubric scoring integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($assignmentIds as $assignmentId) {
            $delete = $db->prepare('DELETE FROM evaluation_scores WHERE evaluator_assignment_id = ?');
            $delete->bind_param('i', $assignmentId);
            $delete->execute();
            $delete->close();
        }
        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM notifications WHERE related_application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM audit_logs WHERE entity_type = ? AND entity_id = ?');
            $entityType = 'evaluator_assignment';
            $delete->bind_param('si', $entityType, $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }
        foreach ($criterionIds as $criterionId) {
            $delete = $db->prepare('DELETE FROM rubric_criteria WHERE criterion_id = ?');
            $delete->bind_param('i', $criterionId);
            $delete->execute();
            $delete->close();
        }
        if ($rubricId !== null) {
            $delete = $db->prepare('DELETE FROM evaluation_rubrics WHERE rubric_id = ?');
            $delete->bind_param('i', $rubricId);
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

            $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
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
