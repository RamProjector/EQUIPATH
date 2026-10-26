<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Applications/ApplicationHistoryService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$userIds = [];
$programId = null;
$periodId = null;
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

    foreach (['owner', 'other', 'coordinator'] as $kind) {
        $email = $kind . '-history-' . $suffix . '@example.invalid';
        $passwordHash = password_hash('history-test-password', PASSWORD_DEFAULT);
        $givenName = ucfirst($kind);
        $familyName = 'HistoryTest';
        $insert = $db->prepare(
            "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        $insert->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insert->execute();
        $userIds[$kind] = (int) $db->insert_id;
        $insert->close();
    }

    $programCode = 'HISTORY-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Applicant history integration test';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $periodName = 'History period ' . $suffix;
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

    $applicationNumber = 'HISTORY-' . strtoupper(substr($suffix, 0, 8));
    $applicationStatus = 'submitted';
    $submittedAt = date('Y-m-d H:i:s');
    $application = $db->prepare(
        'INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $application->bind_param('siiiss', $applicationNumber, $userIds['owner'], $programId, $periodId, $applicationStatus, $submittedAt);
    $application->execute();
    $applicationId = (int) $db->insert_id;
    $application->close();

    $fromStatus = 'draft';
    $toStatus = 'submitted';
    $reason = 'Applicant submitted for screening';
    $history = $db->prepare(
        'INSERT INTO application_status_history (application_id, changed_by, from_status, to_status, reason)
         VALUES (?, ?, ?, ?, ?)'
    );
    $history->bind_param('iisss', $applicationId, $userIds['owner'], $fromStatus, $toStatus, $reason);
    $history->execute();
    $history->close();

    $outcome = 'needs_correction';
    $feedback = 'Please provide the missing employment certificate.';
    $reviewedAt = date('Y-m-d H:i:s');
    $review = $db->prepare(
        'INSERT INTO screening_reviews (application_id, reviewer_user_id, outcome, notes, reviewed_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $review->bind_param('iisss', $applicationId, $userIds['coordinator'], $outcome, $feedback, $reviewedAt);
    $review->execute();
    $review->close();

    $service = new ApplicationHistoryService();
    $historyData = $service->getForApplicant($db, $userIds['owner'], $applicationId);
    if ($historyData['application']['application_id'] !== $applicationId ||
        count($historyData['status_history']) !== 1 ||
        $historyData['status_history'][0]['to_status'] !== 'submitted' ||
        count($historyData['screening_feedback']) !== 1 ||
        $historyData['screening_feedback'][0]['notes'] !== $feedback) {
        throw new RuntimeException('Applicant history or feedback payload was incomplete.');
    }

    try {
        $service->getForApplicant($db, $userIds['other'], $applicationId);
        throw new RuntimeException('A different applicant accessed application history.');
    } catch (DomainException) {
        // The history service enforces ownership.
    }

    fwrite(STDOUT, "PASS: applicant history (owner isolation, status timeline and screening feedback).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: applicant history integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        if ($applicationId !== null) {
            foreach ([
                'DELETE FROM screening_reviews WHERE application_id = ?',
                'DELETE FROM application_status_history WHERE application_id = ?',
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
        $db->close();
    }
}
exit($exitCode);
