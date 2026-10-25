<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Security/SessionManager.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $sessions = new SessionManager();
    $sessions->start();

    echo json_encode([
        'ok' => true,
        'csrf_token' => $sessions->csrfToken(),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('EquiPath CSRF bootstrap failed: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable'], JSON_THROW_ON_ERROR);
}
