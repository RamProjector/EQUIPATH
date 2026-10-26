<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/ApplicationSubmissionService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$userIds = [];
$createdRoleIds = [];
$programId = null;
$periodId = null;
$applicationId = null;
$requirementIds = [];

function submissionEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
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
    $id = (int) $db->insert_id;
    $insert->close();
    $createdRoleIds[] = $id;
    return $id;
}

function submissionInsertDocument(mysqli $db, int $applicationId, int $userId, string $type, int $version, string $status): void
{
    $storageKey = hash('sha256', random_bytes(32));
    $filename = 'test-' . $type . '-v' . $version . '.pdf';
    $mime = 'application/pdf';
    $size = 100;
    $digest = hash('sha256', $type . ':' . $version . ':' . $status);
    $insert = $db->prepare(
        'INSERT INTO application_documents
            (application_id, uploaded_by, document_type, version_number, storage_key,
             original_filename, detected_mime_type, file_size_bytes, sha256, review_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->bind_param('iisisssiss', $applicationId, $userId, $type, $version, $storageKey, $filename, $mime, $size, $digest, $status);
    $insert->execute();
    $insert->close();
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
    $coordinatorRoleId = submissionEnsureRole($db, 'coordinator', 'Coordinator', $createdRoleIds);
    $applicantRoleId = submissionEnsureRole($db, 'applicant', 'Applicant', $createdRoleIds);

    foreach (['applicant', 'coordinator'] as $kind) {
        $email = $kind . '-submission-' . $suffix . '@example.invalid';
        $passwordHash = password_hash('submission-test-password', PASSWORD_DEFAULT);
        $givenName = ucfirst($kind);
        $familyName = 'SubmissionTest';
        $insert = $db->prepare(
            "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $insert->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insert->execute();
        $userIds[$kind] = (int) $db->insert_id;
        $insert->close();
    }
    foreach ([[$userIds['applicant'], $applicantRoleId], [$userIds['coordinator'], $coordinatorRoleId]] as [$userId, $roleId]) {
        $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assign->bind_param('ii', $userId, $roleId);
        $assign->execute();
        $assign->close();
    }

    $programCode = 'SUBMIT-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Application submission integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    foreach ([['identity_document', 'Identity document'], ['experience_portfolio', 'Experience portfolio']] as [$key, $name]) {
        $required = 1;
        $requirement = $db->prepare(
            'INSERT INTO program_requirements (program_id, requirement_key, requirement_name, is_required, is_active)
             VALUES (?, ?, ?, ?, TRUE)'
        );
        $requirement->bind_param('issi', $programId, $key, $name, $required);
        $requirement->execute();
        $requirementIds[] = (int) $db->insert_id;
        $requirement->close();
    }

    $periodName = 'Submission period ' . $suffix;
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

    $applicationNumber = 'SUBMIT-' . strtoupper(substr($suffix, 0, 8));
    $status = 'draft';
    $application = $db->prepare(
        'INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $application->bind_param('siiis', $applicationNumber, $userIds['applicant'], $programId, $periodId, $status);
    $application->execute();
    $applicationId = (int) $db->insert_id;
    $application->close();

    $service = new ApplicationSubmissionService();
    try {
        $service->submit($db, $userIds['applicant'], $applicationId);
        throw new RuntimeException('Submission unexpectedly succeeded with no required evidence.');
    } catch (ApplicationSubmissionIncompleteException $error) {
        if (count($error->missingRequirements) !== 2) {
            throw new RuntimeException('Missing required documents were not enumerated correctly.');
        }
    }

    submissionInsertDocument($db, $applicationId, $userIds['applicant'], 'identity_document', 1, 'pending');
    submissionInsertDocument($db, $applicationId, $userIds['applicant'], 'experience_portfolio', 1, 'pending');
    submissionInsertDocument($db, $applicationId, $userIds['applicant'], 'identity_document', 2, 'rejected');
    try {
        $service->submit($db, $userIds['applicant'], $applicationId);
        throw new RuntimeException('Submission unexpectedly accepted a rejected latest document version.');
    } catch (ApplicationSubmissionIncompleteException $error) {
        $missingKeys = array_column($error->missingRequirements, 'key');
        if (!in_array('identity_document', $missingKeys, true) || in_array('experience_portfolio', $missingKeys, true)) {
            throw new RuntimeException('Latest-version validation did not identify the rejected requirement correctly.');
        }
    }

    submissionInsertDocument($db, $applicationId, $userIds['applicant'], 'identity_document', 3, 'pending');
    $result = $service->submit($db, $userIds['applicant'], $applicationId);
    if ($result['application_status'] !== 'submitted' || $result['previous_status'] !== 'draft' ||
        $result['application_id'] !== $applicationId || $result['submitted_at'] === '') {
        throw new RuntimeException('Successful submission returned an unexpected state.');
    }

    $history = $db->prepare(
        "SELECT COUNT(*) AS total FROM application_status_history
         WHERE application_id = ? AND changed_by = ? AND from_status = 'draft' AND to_status = 'submitted'"
    );
    $history->bind_param('ii', $applicationId, $userIds['applicant']);
    $history->execute();
    $historyCount = (int) $history->get_result()->fetch_assoc()['total'];
    $history->close();
    if ($historyCount !== 1) {
        throw new RuntimeException('Submission status history was not persisted.');
    }

    $notifications = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND notification_type = 'application_submitted' AND related_application_id = ?"
    );
    $notifications->bind_param('ii', $userIds['coordinator'], $applicationId);
    $notifications->execute();
    $notificationCount = (int) $notifications->get_result()->fetch_assoc()['total'];
    $notifications->close();
    if ($notificationCount !== 1) {
        throw new RuntimeException('Coordinator was not notified of the submitted application.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'application.submitted' AND entity_id = ?"
    );
    $audit->bind_param('ii', $userIds['applicant'], $applicationId);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 1) {
        throw new RuntimeException('Submission audit event was not persisted.');
    }

    fwrite(STDOUT, "PASS: application submission (required evidence, latest-version review status, status history, coordinator notification and audit).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: application submission integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        if ($applicationId !== null) {
            foreach ([
                'DELETE FROM notifications WHERE related_application_id = ?',
                'DELETE FROM application_status_history WHERE application_id = ?',
                'DELETE FROM application_documents WHERE application_id = ?',
            ] as $sql) {
                $delete = $db->prepare($sql);
                $delete->bind_param('i', $applicationId);
                $delete->execute();
                $delete->close();
            }
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
        foreach ($requirementIds as $requirementId) {
            $delete = $db->prepare('DELETE FROM program_requirements WHERE program_requirement_id = ?');
            $delete->bind_param('i', $requirementId);
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
