<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/Authenticator.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/LoginRateLimiter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'unsupported_media_type']);
    exit;
}

$db = null;
try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_string($payload['email'] ?? null) || !is_string($payload['password'] ?? null)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_request']);
        exit;
    }

    $email = strtolower(trim($payload['email']));
    $password = $payload['password'];
    if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > 4096) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_request']);
        exit;
    }

    $sessions = new SessionManager();
    $sessions->start();
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!$sessions->isValidCsrfToken(is_string($csrf) ? $csrf : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_validation_failed']);
        exit;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_request']);
        exit;
    }

    $db = ConnectionFactory::createFromEnvironment();
    $limiter = new LoginRateLimiter($db);
    if ($limiter->isBlocked($email, $ip)) {
        http_response_code(429);
        header('Retry-After: 900');
        echo json_encode(['ok' => false, 'error' => 'login_temporarily_unavailable']);
        exit;
    }

    $identity = (new Authenticator())->authenticate($db, $email, $password);
    if ($identity === null) {
        $limiter->recordFailure($email, $ip);
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'invalid_credentials']);
        exit;
    }

    $limiter->clear($email, $ip);
    $sessions->establishIdentity($identity);
    echo json_encode(['ok' => true, 'user' => $identity], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_request']);
} catch (Throwable $error) {
    error_log('EquiPath authentication endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
