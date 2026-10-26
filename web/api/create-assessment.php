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
        !isset($payload['application_id'], $payload['assessment_type'], $payload['starts_at'], $payload['ends_at']) ||
        !is_int($payload['application_id']) || $payload['application_id'] < 1 ||
        !is_string($payload['assessment_type']) ||
        !is_string($payload['starts_at']) || !is_string($payload['ends_at']) ||
        (isset($payload['location_or_link']) && !is_string($payload['location_or_link'])) ||
        (isset($payload['notes']) && !is_string($payload['notes']))) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_assessment_details']);
        exit;
    }

    $startsAt = str_replace('T', ' ', $payload['starts_at']);
    $endsAt = str_replace('T', ' ', $payload['ends_at']);
    if (strlen($startsAt) === 16) {
        $startsAt .= ':00';
    }
    if (strlen($endsAt) === 16) {
        $endsAt .= ':00';
    }
    $location = isset($payload['location_or_link']) && trim($payload['location_or_link']) !== ''
        ? trim($payload['location_or_link'])
        : null;
    $notes = isset($payload['notes']) && trim($payload['notes']) !== ''
        ? trim($payload['notes'])
        : null;

    $result = (new AssessmentService())->schedule(
        $db,
        $coordinatorUserId,
        $payload['application_id'],
        $payload['assessment_type'],
        $startsAt,
        $endsAt,
        $location,
        $notes
    );
    http_response_code(201);
    echo json_encode(['ok' => true, 'assessment' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_assessment_details']);
} catch (DomainException $error) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'assessment_not_allowed']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath assessment scheduling endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
