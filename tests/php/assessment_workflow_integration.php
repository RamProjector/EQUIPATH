<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Assessments/AssessmentService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$createdRoleIds = [];
$programId = null;
$periodId = null;
$applicationId = null;
$eventIds = [];

function assessmentEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
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
    $coordinatorRoleId = assessmentEnsureRole($db, 'coordinator', 'Coordinator', $createdRoleIds);
    $evaluatorRoleId = assessmentEnsureRole($db, 'evaluator', 'Evaluator', $createdRoleIds);
    $applicantRoleId = assessmentEnsureRole($db, 'applicant', 'Applicant', $createdRoleIds);

    $passwordHash = password_hash('assessment-test-password', PASSWORD_DEFAULT);
    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    foreach (['applicant', 'coordinator', 'evaluator'] as $kind) {
        $email = $kind . '-assessment-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'AssessmentTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = (int) $db->insert_id;
    }
    $insertUser->close();

    foreach ([[$userIds['applicant'], $applicantRoleId], [$userIds['coordinator'], $coordinatorRoleId], [$userIds['evaluator'], $evaluatorRoleId]] as [$userId, $roleId]) {
        $assignRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assignRole->bind_param('ii', $userId, $roleId);
        $assignRole->execute();
        $assignRole->close();
    }

    $programCode = 'ASSESS-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Assessment integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $periodName = 'Assessment period ' . $suffix;
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

    $applicationNumber = 'ASSESS-' . strtoupper(substr($suffix, 0, 8));
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

    $assignmentStatus = 'submitted';
    $assignmentSubmittedAt = date('Y-m-d H:i:s');
    $assignment = $db->prepare(
        'INSERT INTO evaluator_assignments
            (application_id, evaluator_user_id, assigned_by, assignment_status, submitted_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $assignment->bind_param('iiiss', $applicationId, $userIds['evaluator'], $userIds['coordinator'], $assignmentStatus, $assignmentSubmittedAt);
    $assignment->execute();
    $assignmentId = (int) $db->insert_id;
    $assignment->close();

    $clock = $db->query(
        "SELECT DATE_FORMAT(DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 DAY), '%Y-%m-%d %H:%i:%s') AS start_time,
                DATE_FORMAT(DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 DAY), '%Y-%m-%d %H:%i:%s') AS second_start,
                DATE_FORMAT(DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 HOUR), '%Y-%m-%d %H:%i:%s') AS past_start,
                DATE_FORMAT(DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR), '%Y-%m-%d %H:%i:%s') AS past_end"
    )->fetch_assoc();
    $start = (string) $clock['start_time'];
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $start);
    if ($startDate === false) {
        throw new RuntimeException('Could not prepare future assessment time for test.');
    }
    $end = $startDate->modify('+1 hour')->format('Y-m-d H:i:s');
    $secondStart = (string) $clock['second_start'];
    $secondStartDate = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $secondStart);
    if ($secondStartDate === false) {
        throw new RuntimeException('Could not prepare second assessment time for test.');
    }
    $secondEnd = $secondStartDate->modify('+2 hours')->format('Y-m-d H:i:s');

    $service = new AssessmentService();
    foreach ([
        ['interview', $start, $end],
        ['examination', $secondStart, $secondEnd],
    ] as [$type, $startsAt, $endsAt]) {
        $scheduled = $service->schedule(
            $db,
            $userIds['coordinator'],
            $applicationId,
            $type,
            $startsAt,
            $endsAt,
            'ADFC assessment room',
            'Integration test only'
        );
        if ($scheduled['application_status'] !== 'assessment_scheduled' ||
            $scheduled['event_status'] !== 'scheduled') {
            throw new RuntimeException('Assessment scheduling did not return the expected application/event state.');
        }
        $eventIds[] = $scheduled['assessment_event_id'];
    }

    $appStatusQuery = $db->prepare('SELECT application_status FROM applications WHERE application_id = ?');
    $appStatusQuery->bind_param('i', $applicationId);
    $appStatusQuery->execute();
    $statusAfterSchedule = (string) $appStatusQuery->get_result()->fetch_assoc()['application_status'];
    $appStatusQuery->close();
    if ($statusAfterSchedule !== 'assessment_scheduled') {
        throw new RuntimeException('Scheduling did not move the application to assessment_scheduled.');
    }

    $scheduledNotices = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'assessment_scheduled'"
    );
    $scheduledNotices->bind_param('ii', $userIds['applicant'], $applicationId);
    $scheduledNotices->execute();
    $scheduledNoticeCount = (int) $scheduledNotices->get_result()->fetch_assoc()['total'];
    $scheduledNotices->close();
    if ($scheduledNoticeCount !== 2) {
        throw new RuntimeException('Expected one applicant notification for each scheduled assessment.');
    }

    $pastStart = (string) $clock['past_start'];
    $pastEnd = (string) $clock['past_end'];
    foreach ($eventIds as $eventId) {
        $updateTimes = $db->prepare(
            'UPDATE assessment_events SET starts_at = ?, ends_at = ? WHERE assessment_event_id = ?'
        );
        $updateTimes->bind_param('ssi', $pastStart, $pastEnd, $eventId);
        $updateTimes->execute();
        $updateTimes->close();
    }

    $result = $service->recordResult(
        $db,
        $userIds['coordinator'],
        $eventIds[0],
        'passed',
        '85.50',
        'Assessment requirements met'
    );
    if ($result['outcome'] !== 'passed' || $result['application_status'] !== 'assessment_scheduled') {
        throw new RuntimeException('First result should complete one event while the application still has another assessment.');
    }

    $result = $service->recordResult(
        $db,
        $userIds['coordinator'],
        $eventIds[1],
        'incomplete',
        null,
        'Follow-up is required'
    );
    if ($result['outcome'] !== 'incomplete' || $result['application_status'] !== 'awaiting_decision') {
        throw new RuntimeException('All recorded assessment results should move the application to awaiting_decision.');
    }

    $resultQuery = $db->prepare(
        'SELECT COUNT(*) AS total FROM assessment_results WHERE assessment_event_id IN (?, ?)'
    );
    $resultQuery->bind_param('ii', $eventIds[0], $eventIds[1]);
    $resultQuery->execute();
    $resultCount = (int) $resultQuery->get_result()->fetch_assoc()['total'];
    $resultQuery->close();
    if ($resultCount !== 2) {
        throw new RuntimeException('Expected two recorded assessment results.');
    }

    $completedQuery = $db->prepare(
        "SELECT COUNT(*) AS total FROM assessment_events
         WHERE assessment_event_id IN (?, ?) AND event_status = 'completed'"
    );
    $completedQuery->bind_param('ii', $eventIds[0], $eventIds[1]);
    $completedQuery->execute();
    $completedCount = (int) $completedQuery->get_result()->fetch_assoc()['total'];
    $completedQuery->close();
    if ($completedCount !== 2) {
        throw new RuntimeException('Recording results did not complete both assessment events.');
    }

    $historyQuery = $db->prepare(
        "SELECT COUNT(*) AS total FROM application_status_history
         WHERE application_id = ? AND changed_by = ? AND from_status = 'assessment_scheduled'
           AND to_status = 'awaiting_decision'"
    );
    $historyQuery->bind_param('ii', $applicationId, $userIds['coordinator']);
    $historyQuery->execute();
    $historyCount = (int) $historyQuery->get_result()->fetch_assoc()['total'];
    $historyQuery->close();
    if ($historyCount !== 1) {
        throw new RuntimeException('Application status history did not record the final assessment transition.');
    }

    $resultNotices = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'assessment_result'"
    );
    $resultNotices->bind_param('ii', $userIds['applicant'], $applicationId);
    $resultNotices->execute();
    $resultNoticeCount = (int) $resultNotices->get_result()->fetch_assoc()['total'];
    $resultNotices->close();
    if ($resultNoticeCount !== 2) {
        throw new RuntimeException('Expected one applicant notification for each recorded result.');
    }

    try {
        $service->recordResult($db, $userIds['coordinator'], $eventIds[0], 'passed', '85.50', 'Duplicate');
        throw new RuntimeException('Service allowed a second result for the same event.');
    } catch (DomainException) {
        // The schema and service must preserve one result per event.
    }

    try {
        $service->schedule(
            $db,
            $userIds['coordinator'],
            $applicationId,
            'interview',
            $pastStart,
            $pastEnd,
            null,
            null
        );
        throw new RuntimeException('Service accepted a past assessment start time.');
    } catch (InvalidArgumentException) {
        // Past scheduling must be rejected.
    }

    fwrite(STDOUT, "PASS: assessment scheduling/results (future schedule, notifications, result locking, status history and awaiting-decision transition).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: assessment workflow integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($eventIds as $eventId) {
            $delete = $db->prepare('DELETE FROM assessment_results WHERE assessment_event_id = ?');
            $delete->bind_param('i', $eventId);
            $delete->execute();
            $delete->close();
        }
        if ($applicationId !== null) {
            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM notifications WHERE related_application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
            $delete->bind_param('i', $userIds['coordinator']);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM application_status_history WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            foreach ($eventIds as $eventId) {
                $delete = $db->prepare('DELETE FROM assessment_events WHERE assessment_event_id = ?');
                $delete->bind_param('i', $eventId);
                $delete->execute();
                $delete->close();
            }
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
