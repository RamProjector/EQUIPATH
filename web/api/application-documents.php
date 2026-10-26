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
$applicationValue = $_GET['application_id'] ?? null;
if (!is_string($applicationValue) || !ctype_digit($applicationValue) || (int) $applicationValue < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_application_details']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $roles = $access->requireAnyRole($db, ['applicant', 'coordinator']);
    $isCoordinator = in_array('coordinator', $roles, true);

    $applicationId = (int) $applicationValue;
    $owner = $db->prepare(
        'SELECT application_id, applicant_user_id, application_status FROM applications
         WHERE application_id = ? LIMIT 1'
    );
    $owner->bind_param('i', $applicationId);
    $owner->execute();
    $application = $owner->get_result()->fetch_assoc();
    $owner->close();
    $owned = is_array($application) && (int) $application['applicant_user_id'] === $userId;
    $staffCanReview = $isCoordinator && is_array($application) && in_array((string) $application['application_status'], ['submitted', 'screening', 'needs_correction'], true);
    if (!$owned && !$staffCanReview) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'application_not_found']);
        exit;
    }

    $statement = $db->prepare(
        'SELECT document_id, document_type, version_number, original_filename,
                file_size_bytes, review_status, uploaded_at
         FROM application_documents
         WHERE application_id = ?
         ORDER BY document_type, version_number DESC, document_id DESC
         LIMIT 200'
    );
    $statement->bind_param('i', $applicationId);
    $statement->execute();
    $result = $statement->get_result();
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = [
            'document_id' => (int) $row['document_id'],
            'document_type' => (string) $row['document_type'],
            'version_number' => (int) $row['version_number'],
            'original_filename' => (string) $row['original_filename'],
            'file_size_bytes' => (int) $row['file_size_bytes'],
            'review_status' => (string) $row['review_status'],
            'uploaded_at' => (string) $row['uploaded_at'],
        ];
    }
    $statement->close();

    echo json_encode(['ok' => true, 'documents' => $documents, 'limit' => 200], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath applicant document list endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
