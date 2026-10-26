<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/EvaluationScoringService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$roleIdsCreated = [];
$programIds = [];
$periodId = null;
$applicationId = null;
$assignmentIds = [];
$criterionIds = [];
$rubricIds = [];

function scoringInsertId(mysqli $db): int
{
    return (int) $db->insert_id;
}

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
    $roleId = scoringInsertId($db);
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
    $coordinatorRoleId = scoringEnsureRole($db, 'coordinator', 'Coordinator', $roleIdsCreated);
    $evaluatorRoleId = scoringEnsureRole($db, 'evaluator', 'Evaluator', $roleIdsCreated);

    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $passwordHash = password_hash('scoring-test-password', PASSWORD_DEFAULT);
    foreach (['applicant', 'coordinator', 'evaluator', 'other_evaluator'] as $kind) {
        $email = $kind . '-scoring-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'ScoringTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = scoringInsertId($db);
    }
    $insertUser->close();

    foreach ([
        [$userIds['coordinator'], $coordinatorRoleId],
        [$userIds['evaluator'], $evaluatorRoleId],
        [$userIds['other_evaluator'], $evaluatorRoleId],
    ] as [$userId, $roleId]) {
        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userId, $roleId);
        $assignRole->execute();
        $assignRole->close();
    }

    foreach (['main', 'other'] as $suffixKey) {
        $programCode = 'SCORE-' . strtoupper(substr($suffix, 0, 7)) . '-' . strtoupper($suffixKey);
        $programName = 'Scoring test program ' . $suffixKey;
        $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
        $program->bind_param('ss', $programCode, $programName);
        $program->execute();
        $programIds[$suffixKey] = scoringInsertId($db);
        $program->close();

        $rubricName = 'Scoring rubric ' . $suffixKey;
        $rubric = $db->prepare(
            'INSERT INTO evaluation_rubrics (program_id, rubric_name, version_number, is_active)
             VALUES (?, ?, 1, TRUE)'
        );
        $rubric->bind_param('is', $programIds[$suffixKey], $rubricName);
        $rubric->execute();
        $rubricIds[$suffixKey] = scoringInsertId($db);
        $rubric->close();

        $criterionCode = 'CRIT-' . strtoupper($suffixKey);
        $criterionName = 'Criterion ' . $suffixKey;
        $maxScore = 10.00;
        $weight = 1.0000;
        $criterion = $db->prepare(
            'INSERT INTO rubric_criteria (rubric_id, criterion_code, criterion_name, max_score, weight)
             VALUES (?, ?, ?, ?, ?)'
        );
        $criterion->bind_param('issdd', $rubricIds[$suffixKey], $criterionCode, $criterionName, $maxScore, $weight);
        $criterion->execute();
        $criterionIds[$suffixKey] = scoringInsertId($db);
        $criterion->close();
    }

    $periodName = 'Scoring period ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = scoringInsertId($db);
    $period->close();

    $applicationNumber = 'SCORE-' . strtoupper(substr($suffix, 0, 8));
    $submittedAt = date('Y-m-d H:i:s');
    $status = 'under_evaluation';
    $application = $db->prepare(
        'INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $application->bind_param('siiiss', $applicationNumber, $userIds['applicant'], $programIds['main'], $periodId, $status, $submittedAt);
    $application->execute();
    $applicationId = scoringInsertId($db);
    $application->close();

    $assignmentStatus = 'assigned';
    $assignment = $db->prepare(
        'INSERT INTO evaluator_assignments
            (application_id, evaluator_user_id, assigned_by, assignment_status)
         VALUES (?, ?, ?, ?)'
    );
    $assignment->bind_param('iiis', $applicationId, $userIds['evaluator'], $userIds['coordinator'], $assignmentStatus);
    $assignment->execute();
    $assignmentIds['assigned'] = scoringInsertId($db);
    $assignment->close();

    $submittedAssignmentStatus = 'submitted';
    $submittedAssignment = $db->prepare(
        'INSERT INTO evaluator_assignments
            (application_id, evaluator_user_id, assigned_by, assignment_status, submitted_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
    );
    $submittedAssignment->bind_param('iiis', $applicationId, $userIds['other_evaluator'], $userIds['coordinator'], $submittedAssignmentStatus);
    $submittedAssignment->execute();
    $assignmentIds['submitted'] = scoringInsertId($db);
    $submittedAssignment->close();

    $service = new EvaluationScoringService();
    $first = $service->saveScore(
        $db,
        $userIds['evaluator'],
        $assignmentIds['assigned'],
        $criterionIds['main'],
        8.50,
        'Evidence aligns with the criterion.'
    );
    if ($first['assignment_status'] !== 'in_progress' ||
        $first['score'] !== 8.5 ||
        $first['criterion_id'] !== $criterionIds['main']) {
        throw new RuntimeException('First score response failed its contract.');
    }

    $second = $service->saveScore(
        $db,
        $userIds['evaluator'],
        $assignmentIds['assigned'],
        $criterionIds['main'],
        9.25,
        'Updated after reviewing the supporting evidence.'
    );
    if ($second['evaluation_score_id'] !== $first['evaluation_score_id'] || $second['score'] !== 9.25) {
        throw new RuntimeException('Updating a score created a duplicate row or returned the wrong score.');
    }

    $stored = $db->prepare(
        'SELECT score, feedback FROM evaluation_scores WHERE evaluation_score_id = ?'
    );
    $stored->bind_param('i', $first['evaluation_score_id']);
    $stored->execute();
    $storedRow = $stored->get_result()->fetch_assoc();
    $stored->close();
    if (!is_array($storedRow) ||
        (float) $storedRow['score'] !== 9.25 ||
        $storedRow['feedback'] !== 'Updated after reviewing the supporting evidence.') {
        throw new RuntimeException('Updated score and feedback were not persisted.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'evaluation.score_saved'
           AND entity_type = 'evaluation_score' AND entity_id = ? AND outcome = 'success'"
    );
    $audit->bind_param('ii', $userIds['evaluator'], $first['evaluation_score_id']);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 2) {
        throw new RuntimeException('Score create/update audit events were missing or duplicated.');
    }

    $attempts = [
        fn() => $service->saveScore($db, $userIds['evaluator'], $assignmentIds['assigned'], $criterionIds['main'], 10.5, ''),
        fn() => $service->saveScore($db, $userIds['other_evaluator'], $assignmentIds['assigned'], $criterionIds['main'], 5.0, ''),
        fn() => $service->saveScore($db, $userIds['evaluator'], $assignmentIds['assigned'], $criterionIds['other'], 5.0, ''),
        fn() => $service->saveScore($db, $userIds['other_evaluator'], $assignmentIds['submitted'], $criterionIds['main'], 5.0, ''),
    ];
    foreach ($attempts as $index => $attempt) {
        try {
            $attempt();
            throw new RuntimeException('Invalid scoring case ' . ($index + 1) . ' was accepted.');
        } catch (DomainException) {
            // Expected: max-score, assignment-owner, rubric and submitted-state checks reject these writes.
        }
    }

    try {
        $service->saveScore($db, $userIds['evaluator'], $assignmentIds['assigned'], $criterionIds['main'], 8.123, '');
        throw new RuntimeException('Score with more than two decimal places was accepted.');
    } catch (InvalidArgumentException) {
        // Expected: DECIMAL(7,2) scores are not silently rounded.
    }

    $storedCount = $db->prepare(
        'SELECT COUNT(*) AS total FROM evaluation_scores WHERE evaluator_assignment_id = ?'
    );
    $storedCount->bind_param('i', $assignmentIds['assigned']);
    $storedCount->execute();
    $scoreCount = (int) $storedCount->get_result()->fetch_assoc()['total'];
    $storedCount->close();
    if ($scoreCount !== 1) {
        throw new RuntimeException('Rejected scoring attempts created additional score records.');
    }

    fwrite(STDOUT, "PASS: evaluation scoring service (active rubric, assignment ownership, score bounds, updates, audit, and submitted-state rejection).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: evaluation scoring integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($assignmentIds as $assignmentId) {
            $delete = $db->prepare('DELETE FROM evaluation_scores WHERE evaluator_assignment_id = ?');
            $delete->bind_param('i', $assignmentId);
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
        if ($applicationId !== null) {
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
        foreach ($rubricIds as $rubricId) {
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
        foreach ($programIds as $programId) {
            $delete = $db->prepare('DELETE FROM academic_programs WHERE program_id = ?');
            $delete->bind_param('i', $programId);
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
