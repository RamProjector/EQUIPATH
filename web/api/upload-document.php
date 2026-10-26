<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';
require_once dirname(__DIR__) . '/src/Documents/PortfolioDocumentService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > PortfolioDocumentService::DEFAULT_MAX_BYTES + 65536) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();

    if (!$sessions->isValidCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_validation_failed']);
        exit;
    }

    $access = new AccessControl();
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['applicant']);

    $applicationValue = $_POST['application_id'] ?? null;
    $documentType = $_POST['document_type'] ?? null;
    if (!is_string($applicationValue) || !ctype_digit($applicationValue) ||
        (int) $applicationValue < 1 || !is_string($documentType) ||
        !preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $documentType)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_document_details']);
        exit;
    }

    $file = $_FILES['document'] ?? null;
    if (!is_array($file) || !isset($file['name'], $file['type'], $file['tmp_name'], $file['error'], $file['size']) ||
        !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_upload']);
        exit;
    }

    $document = (new PortfolioDocumentService())->upload(
        $db,
        $userId,
        (int) $applicationValue,
        $documentType,
        $file
    );

    http_response_code(201);
    echo json_encode(['ok' => true, 'document' => $document], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (LengthException $error) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'document_too_large']);
} catch (DomainException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'document_rejected']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath document upload endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
