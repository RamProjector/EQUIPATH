<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';
require_once dirname(__DIR__) . '/src/Assessments/AssessmentService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
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
    $coordinatorUserId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['coordinator']);

    $rawBody = file_get_contents('php://input', false, null, 0, 16385);
    if (is_string($rawBody) && strlen($rawBody) > 16384) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
        exit;
    }
    if (!is_string($rawBody) || $rawBody === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }
    try {
        $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }

    if (!is_array($payload) ||
        !isset($payload['assessment_event_id'], $payload['outcome']) ||
        !is_int($payload['assessment_event_id']) || $payload['assessment_event_id'] < 1 ||
        !is_string($payload['outcome']) ||
        (array_key_exists('feedback', $payload) && $payload['feedback'] !== null && !is_string($payload['feedback'])) ||
        (array_key_exists('score', $payload) &&
            $payload['score'] !== null &&
            !is_int($payload['score']) &&
            !is_float($payload['score']) &&
            !is_string($payload['score']))) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_assessment_result']);
        exit;
    }

    $score = null;
    if (array_key_exists('score', $payload) && $payload['score'] !== null) {
        $score = (string) $payload['score'];
    }
    $feedback = isset($payload['feedback']) && trim((string) $payload['feedback']) !== ''
        ? trim((string) $payload['feedback'])
        : null;

    $result = (new AssessmentService())->recordResult(
        $db,
        $coordinatorUserId,
        $payload['assessment_event_id'],
        $payload['outcome'],
        $score,
        $feedback
    );
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_assessment_result']);
} catch (DomainException $error) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'assessment_result_not_allowed']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath assessment result endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
