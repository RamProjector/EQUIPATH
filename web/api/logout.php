<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Security/SessionManager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

try {
    $sessions = new SessionManager();
    $sessions->start();

    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$sessions->isValidCsrfToken(is_string($csrf) ? $csrf : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_validation_failed']);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'authentication_required']);
        exit;
    }

    $sessions->destroy();
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    error_log('EquiPath logout endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
}
