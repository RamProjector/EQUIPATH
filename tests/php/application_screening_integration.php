<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/ApplicationScreeningService.php';
require_once dirname(__DIR__, 2) . '/web/src/Applications/ApplicationDocumentReviewService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$roleIdsCreated = [];
$userIds = [];
$programId = null;
$periodId = null;
$applicationIds = [];
$documentIds = [];
$requirementIds = [];

function screeningInsertId(mysqli $db): int
{
    return (int) $db->insert_id;
}

function screeningInsertDocument(mysqli $db, int $applicationId, int $userId, string $type, int $version): int
{
    $storageKey = hash('sha256', random_bytes(32));
    $filename = 'screening-' . $type . '-v' . $version . '.pdf';
    $mime = 'application/pdf';
    $size = 128;
    $digest = hash('sha256', $type . ':' . $version);
    $status = 'pending';
    $insert = $db->prepare(
        'INSERT INTO application_documents
            (application_id, uploaded_by, document_type, version_number, storage_key,
             original_filename, detected_mime_type, file_size_bytes, sha256, review_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->bind_param('iisisssiss', $applicationId, $userId, $type, $version, $storageKey, $filename, $mime, $size, $digest, $status);
    $insert->execute();
    $id = (int) $db->insert_id;
    $insert->close();
    return $id;
}

function screeningEnsureRole(mysqli $db, string $key, string $label, array &$createdRoleIds): int
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
    $roleId = screeningInsertId($db);
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

    $coordinatorRoleId = screeningEnsureRole($db, 'coordinator', 'Coordinator', $roleIdsCreated);
    $applicantRoleId = screeningEnsureRole($db, 'applicant', 'Applicant', $roleIdsCreated);

    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $passwordHash = password_hash('screening-test-password', PASSWORD_DEFAULT);
    foreach (['coordinator', 'applicant'] as $kind) {
        $email = $kind . '-screening-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'ScreeningTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = screeningInsertId($db);
    }
    $insertUser->close();

    foreach ([
        [$userIds['coordinator'], $coordinatorRoleId],
        [$userIds['applicant'], $applicantRoleId],
    ] as [$userId, $roleId]) {
        $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assign->bind_param('ii', $userId, $roleId);
        $assign->execute();
        $assign->close();
    }

    $programCode = 'SCREEN-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Screening integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = screeningInsertId($db);
    $program->close();

    foreach ([['identity_document', 'Identity document'], ['employment_certificate', 'Employment certificate']] as [$key, $name]) {
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

    $periodName = 'Screening period ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = screeningInsertId($db);
    $period->close();

    foreach (['submitted', 'draft'] as $status) {
        $applicationNumber = 'SCREEN-' . strtoupper(substr($suffix, 0, 8)) . '-' . strtoupper($status);
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
        $applicationIds[$status] = screeningInsertId($db);
        $application->close();
    }

    $documentIds['identity'] = screeningInsertDocument($db, $applicationIds['submitted'], $userIds['applicant'], 'identity_document', 1);
    $documentIds['employment_v1'] = screeningInsertDocument($db, $applicationIds['submitted'], $userIds['applicant'], 'employment_certificate', 1);
    $documentIds['employment_v2'] = screeningInsertDocument($db, $applicationIds['submitted'], $userIds['applicant'], 'employment_certificate', 2);

    $service = new ApplicationScreeningService();
    $result = $service->review(
        $db,
        $userIds['coordinator'],
        $applicationIds['submitted'],
        'needs_correction',
        'Please provide the missing employment certificate.'
    );
    if ($result['previous_status'] !== 'submitted' ||
        $result['application_status'] !== 'needs_correction' ||
        $result['outcome'] !== 'needs_correction') {
        throw new RuntimeException('Screening response did not reflect the expected state transition.');
    }

    $check = $db->prepare('SELECT application_status FROM applications WHERE application_id = ?');
    $check->bind_param('i', $applicationIds['submitted']);
    $check->execute();
    $storedStatus = $check->get_result()->fetch_assoc()['application_status'] ?? null;
    $check->close();
    if ($storedStatus !== 'needs_correction') {
        throw new RuntimeException('Screening did not persist the application status.');
    }

    $review = $db->prepare(
        'SELECT COUNT(*) AS total FROM screening_reviews
         WHERE application_id = ? AND reviewer_user_id = ? AND outcome = ? AND notes = ?'
    );
    $reviewOutcome = 'needs_correction';
    $reviewNotes = 'Please provide the missing employment certificate.';
    $review->bind_param('iiss', $applicationIds['submitted'], $userIds['coordinator'], $reviewOutcome, $reviewNotes);
    $review->execute();
    $reviewCount = (int) $review->get_result()->fetch_assoc()['total'];
    $review->close();
    if ($reviewCount !== 1) {
        throw new RuntimeException('Screening did not create the expected review record.');
    }

    $history = $db->prepare(
        "SELECT COUNT(*) AS total FROM application_status_history
         WHERE application_id = ? AND changed_by = ? AND from_status = 'submitted'
           AND to_status = 'needs_correction'"
    );
    $history->bind_param('ii', $applicationIds['submitted'], $userIds['coordinator']);
    $history->execute();
    $historyCount = (int) $history->get_result()->fetch_assoc()['total'];
    $history->close();
    if ($historyCount !== 1) {
        throw new RuntimeException('Screening did not create the expected status history record.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'application.screened'
           AND entity_type = 'application' AND entity_id = ? AND outcome = 'success'"
    );
    $audit->bind_param('ii', $userIds['coordinator'], $applicationIds['submitted']);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 1) {
        throw new RuntimeException('Screening did not create the expected audit record.');
    }

    $notification = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'application_screening'
           AND message LIKE '%missing employment certificate%'"
    );
    $notification->bind_param('ii', $userIds['applicant'], $applicationIds['submitted']);
    $notification->execute();
    $notificationCount = (int) $notification->get_result()->fetch_assoc()['total'];
    $notification->close();
    if ($notificationCount !== 1) {
        throw new RuntimeException('Screening did not create the expected applicant notification.');
    }

    $documentReview = new ApplicationDocumentReviewService();
    $acceptedDocument = $documentReview->review(
        $db,
        $userIds['coordinator'],
        $documentIds['identity'],
        'accepted',
        ''
    );
    if ($acceptedDocument['review_status'] !== 'accepted' ||
        $acceptedDocument['application_status'] !== 'needs_correction') {
        throw new RuntimeException('Accepted document review did not preserve the existing application state.');
    }

    try {
        $documentReview->review($db, $userIds['coordinator'], $documentIds['identity'], 'rejected', 'Conflicting second review.');
        throw new RuntimeException('A non-pending document was reviewed a second time.');
    } catch (DomainException) {
        // Only a pending document version can be reviewed.
    }

    try {
        $documentReview->review($db, $userIds['coordinator'], $documentIds['employment_v1'], 'accepted', '');
        throw new RuntimeException('An older document version was reviewed while a newer version existed.');
    } catch (DomainException) {
        // Only the latest version can be reviewed.
    }

    $replacement = $documentReview->review(
        $db,
        $userIds['coordinator'],
        $documentIds['employment_v2'],
        'replacement_requested',
        'The scan is incomplete; please upload the complete certificate.'
    );
    if ($replacement['review_status'] !== 'replacement_requested' ||
        $replacement['application_status'] !== 'needs_correction') {
        throw new RuntimeException('Replacement request did not preserve the correction state.');
    }
    $documentNotification = $db->prepare(
        "SELECT COUNT(*) AS total FROM notifications
         WHERE recipient_user_id = ? AND related_application_id = ?
           AND notification_type = 'application_document_review'
           AND message LIKE '%scan is incomplete%'"
    );
    $documentNotification->bind_param('ii', $userIds['applicant'], $applicationIds['submitted']);
    $documentNotification->execute();
    $documentNotificationCount = (int) $documentNotification->get_result()->fetch_assoc()['total'];
    $documentNotification->close();
    if ($documentNotificationCount !== 1) {
        throw new RuntimeException('Document replacement request did not notify the applicant.');
    }

    try {
        $service->review($db, $userIds['coordinator'], $applicationIds['draft'], 'eligible', '');
        throw new RuntimeException('A draft application was accepted for screening.');
    } catch (DomainException) {
        // Expected: draft applications are not eligible for screening.
    }

    $sideEffects = $db->prepare(
        "SELECT
            (SELECT COUNT(*) FROM screening_reviews WHERE application_id = ?) +
            (SELECT COUNT(*) FROM notifications WHERE related_application_id = ?) +
            (SELECT COUNT(*) FROM application_status_history WHERE application_id = ? AND changed_by = ?) AS total"
    );
    $sideEffects->bind_param(
        'iiii',
        $applicationIds['draft'],
        $applicationIds['draft'],
        $applicationIds['draft'],
        $userIds['coordinator']
    );
    $sideEffects->execute();
    $sideEffectCount = (int) $sideEffects->get_result()->fetch_assoc()['total'];
    $sideEffects->close();
    if ($sideEffectCount !== 0) {
        throw new RuntimeException('Rejected draft screening left partial workflow records.');
    }

    try {
        $service->review($db, $userIds['coordinator'], $applicationIds['submitted'], 'approve', '');
        throw new RuntimeException('Unsupported screening outcome was accepted.');
    } catch (InvalidArgumentException) {
        // Expected: only defined screening outcomes are accepted.
    }

    fwrite(STDOUT, "PASS: screening/document review services (status transitions, latest-version checks, review/history/audit/notification transactions, invalid state and outcome rejection).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: screening integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($applicationIds as $applicationId) {
            foreach ([
                'DELETE FROM screening_reviews WHERE application_id = ?',
                'DELETE FROM notifications WHERE related_application_id = ?',
                'DELETE FROM application_status_history WHERE application_id = ?',
                'DELETE FROM application_documents WHERE application_id = ?',
            ] as $sql) {
                $delete = $db->prepare($sql);
                $delete->bind_param('i', $applicationId);
                $delete->execute();
                $delete->close();
            }
        }

        if ($userIds !== []) {
            foreach ($userIds as $userId) {
                $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
                $delete->bind_param('i', $userId);
                $delete->execute();
                $delete->close();
            }
        }

        foreach ($applicationIds as $applicationId) {
            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
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

        foreach ($requirementIds as $requirementId) {
            $delete = $db->prepare('DELETE FROM program_requirements WHERE program_requirement_id = ?');
            $delete->bind_param('i', $requirementId);
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
