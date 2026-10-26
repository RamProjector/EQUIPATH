<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['coordinator']);

    $result = $db->query(
        "SELECT a.application_id, a.application_number, a.application_status,
                a.submitted_at, a.updated_at, a.program_id,
                u.given_name, u.family_name, p.program_code, p.program_name,
                (SELECT COUNT(*) FROM application_documents d
                 WHERE d.application_id = a.application_id) AS document_count,
                (SELECT COUNT(*)
                 FROM program_requirements pr
                 WHERE pr.program_id = a.program_id
                   AND pr.is_active = TRUE AND pr.is_required = TRUE) AS required_document_count,
                (SELECT COUNT(*)
                 FROM program_requirements pr
                 WHERE pr.program_id = a.program_id
                   AND pr.is_active = TRUE AND pr.is_required = TRUE
                   AND NOT EXISTS (
                     SELECT 1 FROM application_documents d
                     WHERE d.application_id = a.application_id
                       AND d.document_type = pr.requirement_key
                       AND d.version_number = (
                         SELECT MAX(latest.version_number)
                         FROM application_documents latest
                         WHERE latest.application_id = d.application_id
                           AND latest.document_type = d.document_type
                       )
                       AND d.review_status IN ('pending', 'accepted')
                       AND d.detected_mime_type = 'application/pdf'
                   )) AS missing_required_document_count,
                (SELECT COUNT(*)
                 FROM program_requirements pr
                 WHERE pr.program_id = a.program_id
                   AND pr.is_active = TRUE AND pr.is_required = TRUE
                   AND NOT EXISTS (
                     SELECT 1 FROM application_documents d
                     WHERE d.application_id = a.application_id
                       AND d.document_type = pr.requirement_key
                       AND d.version_number = (
                         SELECT MAX(latest.version_number)
                         FROM application_documents latest
                         WHERE latest.application_id = d.application_id
                           AND latest.document_type = d.document_type
                       )
                       AND d.review_status = 'accepted'
                       AND d.detected_mime_type = 'application/pdf'
                   )) AS unaccepted_required_document_count
         FROM applications a
         INNER JOIN users u ON u.user_id = a.applicant_user_id
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         WHERE a.application_status IN ('submitted', 'screening', 'needs_correction')
         ORDER BY a.updated_at ASC, a.application_id ASC
         LIMIT 100"
    );
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $applications[] = [
            'application_id' => (int) $row['application_id'],
            'application_number' => (string) $row['application_number'],
            'application_status' => (string) $row['application_status'],
            'submitted_at' => $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
            'updated_at' => (string) $row['updated_at'],
            'program_id' => (int) $row['program_id'],
            'program' => [
                'code' => (string) $row['program_code'],
                'name' => (string) $row['program_name'],
            ],
            'applicant' => [
                'given_name' => (string) $row['given_name'],
                'family_name' => (string) $row['family_name'],
            ],
            'document_count' => (int) $row['document_count'],
            'required_document_count' => (int) $row['required_document_count'],
            'missing_required_document_count' => (int) $row['missing_required_document_count'],
            'unaccepted_required_document_count' => (int) $row['unaccepted_required_document_count'],
        ];
    }
    echo json_encode(['ok' => true, 'applications' => $applications, 'limit' => 100], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath screening candidates endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
