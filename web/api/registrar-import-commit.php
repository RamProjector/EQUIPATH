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
$maxPayloadBytes = 8192;
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
        $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }
    $previewToken = is_array($payload) ? ($payload['preview_token'] ?? null) : null;
    $pending = $_SESSION['registrar_import_preview'] ?? null;
    if (!is_string($previewToken) || !preg_match('/^[a-f0-9]{64}$/D', $previewToken) ||
        !is_array($pending) ||
        !isset($pending['token_hash'], $pending['registrar_user_id'], $pending['source_system'], $pending['rows'], $pending['created_at']) ||
        !is_string($pending['token_hash']) ||
        !is_int($pending['registrar_user_id']) ||
        $pending['registrar_user_id'] !== $registrarUserId ||
        !is_string($pending['source_system']) ||
        !is_array($pending['rows']) ||
        !is_int($pending['created_at']) ||
        $pending['created_at'] > time() ||
        time() - $pending['created_at'] > 900 ||
        !hash_equals($pending['token_hash'], hash('sha256', $previewToken))) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'preview_missing_expired_or_invalid']);
        exit;
    }

    // The client cannot submit or alter rows: commit uses the exact rows stored
    // server-side by the preview endpoint and re-runs reconciliation in-transaction.
    $result = (new RegistrarImportService())->commit(
        $db,
        $registrarUserId,
        $pending['source_system'],
        $pending['rows']
    );
    unset($_SESSION['registrar_import_preview']);
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
