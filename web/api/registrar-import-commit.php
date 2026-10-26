<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';
require_once dirname(__DIR__) . '/src/Integration/RegistrarImportService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$maxPayloadBytes = 1048576;
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxPayloadBytes) {
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
    $registrarUserId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['registrar']);

    $rawBody = file_get_contents('php://input', false, null, 0, $maxPayloadBytes + 1);
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > $maxPayloadBytes) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
        exit;
    }
    try {
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }
    if (!is_array($payload) || !is_string($payload['source_system'] ?? null) ||
        !is_array($payload['rows'] ?? null) || !array_is_list($payload['rows'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_import_rows']);
        exit;
    }

    $result = (new RegistrarImportService())->commit(
        $db,
        $registrarUserId,
        $payload['source_system'],
        $payload['rows']
    );
    foreach ($result['rows'] as &$row) unset($row['local_entity_id']);
    unset($row);
    http_response_code(201);
    echo json_encode(['ok' => true, 'batch' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_import_rows']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath registrar import commit failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
