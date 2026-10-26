<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';
require_once dirname(__DIR__) . '/src/Applications/EvaluatorScoringService.php';

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
    $evaluatorUserId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['evaluator']);

    $rawBody = file_get_contents('php://input', false, null, 0, $maxPayloadBytes + 1);
    if (is_string($rawBody) && strlen($rawBody) > $maxPayloadBytes) {
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
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }

    if (!is_array($payload) ||
        !isset($payload['evaluator_assignment_id'], $payload['rubric_id'], $payload['scores'], $payload['submit']) ||
        !is_int($payload['evaluator_assignment_id']) || $payload['evaluator_assignment_id'] < 1 ||
        !is_int($payload['rubric_id']) || $payload['rubric_id'] < 1 ||
        !is_array($payload['scores']) ||
        !is_bool($payload['submit']) ||
        !array_is_list($payload['scores'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_evaluation_details']);
        exit;
    }

    $result = (new EvaluatorScoringService())->save(
        $db,
        $evaluatorUserId,
        $payload['evaluator_assignment_id'],
        $payload['rubric_id'],
        $payload['scores'],
        $payload['submit']
    );

    echo json_encode([
        'ok' => true,
        'evaluation' => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_evaluation_details']);
} catch (DomainException $error) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'evaluation_not_allowed']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath evaluator scoring endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
