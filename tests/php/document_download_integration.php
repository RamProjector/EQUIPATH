<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Security/AccessControl.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$userIds = [];
$createdRoleIds = [];
$applicationId = null;
$programId = null;
$periodId = null;
$documentId = null;
$storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-download-' . bin2hex(random_bytes(8));
$storageKey = bin2hex(random_bytes(32));
$originalRoot = getenv('EQUIPATH_PRIVATE_STORAGE_PATH');
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$storedPath = null;

function downloadTestInsertId(mysqli $db): int
{
    return (int) $db->insert_id;
}

function runDownloadEndpoint(int $userId, int $documentId): string
{
    $_SESSION['user_id'] = $userId;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['document_id' => (string) $documentId];
    http_response_code(200);
    ob_start();
    require dirname(__DIR__, 2) . '/web/api/download-document.php';
    return (string) ob_get_clean();
}

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );

    $suffix = bin2hex(random_bytes(8));
    $roleIds = [];
    foreach (['applicant' => 'Applicant', 'evaluator' => 'Evaluator', 'coordinator' => 'Coordinator', 'administrator' => 'Administrator'] as $key => $label) {
        $find = $db->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
        $find->bind_param('s', $key);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if (is_array($row)) {
            $roleIds[$key] = (int) $row['role_id'];
        } else {
            $add = $db->prepare('INSERT INTO roles (role_key, display_name) VALUES (?, ?)');
            $add->bind_param('ss', $key, $label);
            $add->execute();
            $roleIds[$key] = downloadTestInsertId($db);
            $createdRoleIds[] = $roleIds[$key];
            $add->close();
        }
    }

    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $passwordHash = password_hash('unused-test-password', PASSWORD_DEFAULT);
    foreach (['owner', 'other', 'evaluator', 'coordinator', 'administrator'] as $kind) {
        $email = $kind . '-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'DocumentDownloadTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = downloadTestInsertId($db);
    }
    $insertUser->close();

    foreach (['owner' => 'applicant', 'other' => 'applicant', 'evaluator' => 'evaluator', 'coordinator' => 'coordinator', 'administrator' => 'administrator'] as $kind => $roleKey) {
        $addRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $addRole->bind_param('ii', $userIds[$kind], $roleIds[$roleKey]);
        $addRole->execute();
        $addRole->close();
    }

    $programCode = 'DL-' . strtoupper(substr($suffix, 0, 8));
    $programName = 'Document download integration';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = downloadTestInsertId($db);
    $program->close();

    $periodName = 'Document download ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = downloadTestInsertId($db);
    $period->close();

    $applicationNumber = 'DL-' . strtoupper(substr($suffix, 0, 12));
    $application = $db->prepare(
        "INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status, submitted_at)
         VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)"
    );
    $application->bind_param('siii', $applicationNumber, $userIds['owner'], $programId, $periodId);
    $application->execute();
    $applicationId = downloadTestInsertId($db);
    $application->close();

    $assignmentStatus = 'assigned';
    $assignment = $db->prepare(
        'INSERT INTO evaluator_assignments (application_id, evaluator_user_id, assigned_by, assignment_status)
         VALUES (?, ?, ?, ?)'
    );
    $assignment->bind_param('iiis', $applicationId, $userIds['evaluator'], $userIds['administrator'], $assignmentStatus);
    $assignment->execute();
    $assignment->close();

    if (!mkdir($storageDir, 0700, true)) {
        throw new RuntimeException('Unable to create isolated private storage test directory.');
    }
    $storedPath = $storageDir . DIRECTORY_SEPARATOR . $storageKey;
    $pdfBytes = "%PDF-1.7\n% EquiPath test fixture only\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
    if (file_put_contents($storedPath, $pdfBytes, LOCK_EX) !== strlen($pdfBytes)) {
        throw new RuntimeException('Unable to create test PDF fixture.');
    }
    chmod($storedPath, 0600);
    putenv('EQUIPATH_PRIVATE_STORAGE_PATH=' . $storageDir);
    unset($_SERVER['DOCUMENT_ROOT']);

    $mimeType = 'application/pdf';
    $filename = 'test-portfolio.pdf';
    $size = strlen($pdfBytes);
    $sha256 = hash('sha256', $pdfBytes);
    $reviewStatus = 'pending';
    $document = $db->prepare(
        'INSERT INTO application_documents
            (application_id, uploaded_by, document_type, version_number, storage_key,
             original_filename, detected_mime_type, file_size_bytes, sha256, review_status)
         VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?)'
    );
    $documentType = 'portfolio';
    $document->bind_param(
        'iissssiss',
        $applicationId,
        $userIds['owner'],
        $documentType,
        $storageKey,
        $filename,
        $mimeType,
        $size,
        $sha256,
        $reviewStatus
    );
    $document->execute();
    $documentId = downloadTestInsertId($db);
    $document->close();

    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException('Unable to start session for download integration test.');
    }

    $ownerBytes = runDownloadEndpoint($userIds['owner'], $documentId);
    if ($ownerBytes !== $pdfBytes || http_response_code() !== 200) {
        throw new RuntimeException('Owning applicant could not download their exact PDF bytes.');
    }

    $evaluatorBytes = runDownloadEndpoint($userIds['evaluator'], $documentId);
    if ($evaluatorBytes !== $pdfBytes || http_response_code() !== 200) {
        throw new RuntimeException('Assigned evaluator could not download their exact PDF bytes.');
    }

    $otherBody = runDownloadEndpoint($userIds['other'], $documentId);
    if (http_response_code() !== 403 || json_decode($otherBody, true)['error'] !== 'access_denied') {
        throw new RuntimeException('Another applicant was not denied document download.');
    }

    $coordinatorBody = runDownloadEndpoint($userIds['coordinator'], $documentId);
    if (http_response_code() !== 200 || $coordinatorBody !== $pdfBytes) {
        throw new RuntimeException('Coordinator could not retrieve evidence for an application in the screening queue.');
    }

    $administratorBody = runDownloadEndpoint($userIds['administrator'], $documentId);
    if (http_response_code() !== 403 || json_decode($administratorBody, true)['error'] !== 'access_denied') {
        throw new RuntimeException('Administrator received implicit document access.');
    }

    $auditCheck = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE entity_type = 'application_document' AND entity_id = ?
           AND action_key = 'portfolio_document.downloaded' AND outcome = 'success'"
    );
    $auditCheck->bind_param('i', $documentId);
    $auditCheck->execute();
    $auditCount = (int) $auditCheck->get_result()->fetch_assoc()['total'];
    $auditCheck->close();
    if ($auditCount !== 2) {
        throw new RuntimeException('Expected exactly two successful document download audit events.');
    }

    fwrite(STDOUT, "PASS: document download integration (owner access, assigned evaluator access, coordinator screening-queue access, cross-applicant denial, no implicit administrator access, integrity verification, audit logging).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: document download integration — ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    if ($originalRoot === false) {
        putenv('EQUIPATH_PRIVATE_STORAGE_PATH');
    } else {
        putenv('EQUIPATH_PRIVATE_STORAGE_PATH=' . $originalRoot);
    }
    if ($originalDocumentRoot !== null) {
        $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
    }

    if ($db instanceof mysqli) {
        if ($documentId !== null) {
            $delete = $db->prepare("DELETE FROM audit_logs WHERE entity_type = 'application_document' AND entity_id = ?");
            $delete->bind_param('i', $documentId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM application_documents WHERE document_id = ?');
            $delete->bind_param('i', $documentId);
            $delete->execute();
            $delete->close();
        }
        if ($applicationId !== null) {
            $delete = $db->prepare('DELETE FROM evaluator_assignments WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
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
        foreach ($createdRoleIds as $roleId) {
            $delete = $db->prepare('DELETE FROM roles WHERE role_id = ?');
            $delete->bind_param('i', $roleId);
            $delete->execute();
            $delete->close();
        }
        $db->close();
    }

    if ($storedPath !== null && is_file($storedPath)) {
        @unlink($storedPath);
    }
    if (is_dir($storageDir)) {
        @rmdir($storageDir);
    }
}
