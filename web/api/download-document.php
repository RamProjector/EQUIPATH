<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: sandbox');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$documentValue = $_GET['document_id'] ?? null;
if (!is_string($documentValue) || !ctype_digit($documentValue) || (int) $documentValue < 1) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_document_id']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();

    $access = new AccessControl();
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['applicant', 'evaluator']);

    $documentId = (int) $documentValue;
    $statement = $db->prepare(
        'SELECT document_id, application_id, storage_key, original_filename,
                detected_mime_type, file_size_bytes, sha256, review_status
         FROM application_documents WHERE document_id = ? LIMIT 1'
    );
    $statement->bind_param('i', $documentId);
    $statement->execute();
    $document = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!is_array($document)) {
        throw new AuthorizationDeniedException('Document access is denied.');
    }

    $applicationId = (int) $document['application_id'];
    // Applicants may retrieve only their own documents; evaluators must have
    // a current assignment. No implicit administrator/staff access is granted.
    $access->requireApplicationAccess($db, $applicationId);

    if ($document['detected_mime_type'] !== 'application/pdf' ||
        !preg_match('/^[a-f0-9]{64}$/D', (string) $document['storage_key']) ||
        !preg_match('/^[a-f0-9]{64}$/D', (string) $document['sha256'])) {
        throw new RuntimeException('Stored document metadata is invalid.');
    }

    $configuredRoot = getenv('EQUIPATH_PRIVATE_STORAGE_PATH');
    if (!is_string($configuredRoot) || trim($configuredRoot) === '') {
        throw new RuntimeException('Private document storage is not configured.');
    }
    $storageRoot = realpath($configuredRoot);
    if ($storageRoot === false || !is_dir($storageRoot) || !is_readable($storageRoot)) {
        throw new RuntimeException('Private document storage is unavailable.');
    }

    $webRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($webRoot) && $webRoot !== '') {
        $resolvedWebRoot = realpath($webRoot);
        if ($resolvedWebRoot !== false) {
            $publicPrefix = rtrim($resolvedWebRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (strcasecmp($storageRoot, $resolvedWebRoot) === 0 ||
                strncasecmp($storageRoot . DIRECTORY_SEPARATOR, $publicPrefix, strlen($publicPrefix)) === 0) {
                throw new RuntimeException('Private document storage must be outside the public web root.');
            }
        }
    }

    $path = $storageRoot . DIRECTORY_SEPARATOR . $document['storage_key'];
    if (!is_file($path) || !is_readable($path) || is_link($path)) {
        throw new RuntimeException('Stored document is unavailable.');
    }

    $bytes = file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) !== (int) $document['file_size_bytes'] ||
        !hash_equals((string) $document['sha256'], hash('sha256', $bytes)) ||
        strpos(substr($bytes, 0, 1024), '%PDF-') === false) {
        throw new RuntimeException('Stored document integrity verification failed.');
    }

    // Log access before sending bytes. If the audit insert fails, fail closed.
    $actionKey = 'portfolio_document.downloaded';
    $entityType = 'application_document';
    $outcome = 'success';
    $metadata = json_encode([
        'application_id' => $applicationId,
        'file_size_bytes' => strlen($bytes),
        'sha256' => $document['sha256'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $audit = $db->prepare(
        'INSERT INTO audit_logs (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $audit->bind_param('ississ', $userId, $actionKey, $entityType, $documentId, $outcome, $metadata);
    $audit->execute();
    $audit->close();

    $filename = (string) $document['original_filename'];
    $fallbackFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'portfolio.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', '\\'], '_', $fallbackFilename) .
        '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    echo $bytes;
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath document download endpoint failure: ' . get_class($error));
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'document_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
