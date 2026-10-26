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
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > RegistrarImportService::MAX_CSV_BYTES + 16384) {
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

    $sourceSystem = $_POST['source_system'] ?? null;
    $file = $_FILES['file'] ?? null;
    if (!is_string($sourceSystem) || !is_array($file) ||
        !isset($file['error'], $file['tmp_name'], $file['size']) ||
        (int) $file['error'] !== UPLOAD_ERR_OK ||
        !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) ||
        (int) $file['size'] < 1 || (int) $file['size'] > RegistrarImportService::MAX_CSV_BYTES) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_import_file']);
        exit;
    }

    $contents = file_get_contents($file['tmp_name']);
    if (!is_string($contents)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_import_file']);
        exit;
    }
    $service = new RegistrarImportService();
    $rows = $service->parseCsv($contents);
    $preview = $service->previewRows($db, $sourceSystem, $rows);
    $counts = ['ready' => 0, 'already_mapped' => 0, 'conflict' => 0, 'invalid' => 0];
    foreach ($preview as $row) {
        $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
    }

    // Keep the exact parsed rows and their server-resolved preview in the
    // authenticated session. The browser receives only an opaque one-use token.
    $previewToken = bin2hex(random_bytes(32));
    $_SESSION['registrar_import_preview'] = [
        'token_hash' => hash('sha256', $previewToken),
        'registrar_user_id' => $registrarUserId,
        'source_system' => strtoupper(trim($sourceSystem)),
        'rows' => $rows,
        'created_at' => time(),
    ];

    foreach ($preview as &$row) {
        unset($row['local_entity_id']);
    }
    unset($row);
    echo json_encode([
        'ok' => true,
        'source_system' => strtoupper(trim($sourceSystem)),
        'counts' => $counts,
        'rows' => $preview,
        'commit_allowed' => $counts['ready'] > 0,
        'preview_token' => $previewToken,
        'preview_expires_in_seconds' => 900,
        'row_limit' => RegistrarImportService::MAX_ROWS,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_import_file_or_rows']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath registrar import preview failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
