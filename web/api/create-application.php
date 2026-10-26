<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
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

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 8192) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => 'payload_too_large']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
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
        !isset($payload['program_id'], $payload['period_id']) ||
        !is_int($payload['program_id']) || $payload['program_id'] < 1 ||
        !is_int($payload['period_id']) || $payload['period_id'] < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'invalid_application_details']);
        exit;
    }

    $programId = $payload['program_id'];
    $periodId = $payload['period_id'];

    // Verify both selected records and the period's actual time window on the
    // server; client-side dropdowns are not an authorization boundary.
    $eligible = $db->prepare(
        "SELECT p.program_id
         FROM academic_programs p
         INNER JOIN application_periods ap ON ap.period_id = ?
         WHERE p.program_id = ?
           AND p.is_active = TRUE
           AND ap.period_status = 'open'
           AND CURRENT_TIMESTAMP BETWEEN ap.opens_at AND ap.closes_at
         LIMIT 1"
    );
    $eligible->bind_param('ii', $periodId, $programId);
    $eligible->execute();
    $isEligible = $eligible->get_result()->fetch_assoc() !== null;
    $eligible->close();

    if (!$isEligible) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'program_or_period_unavailable']);
        exit;
    }

    // A high-entropy, server-generated identifier avoids trusting user input
    // and makes collisions extremely unlikely; the database UNIQUE key is the
    // final integrity guarantee.
    $applicationNumber = 'EQ-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));

    $db->begin_transaction();
    try {
        $insert = $db->prepare(
            "INSERT INTO applications
                (application_number, applicant_user_id, program_id, period_id, application_status)
             VALUES (?, ?, ?, ?, 'draft')"
        );
        $insert->bind_param('siii', $applicationNumber, $userId, $programId, $periodId);
        $insert->execute();
        $applicationId = (int) $db->insert_id;
        $insert->close();

        $history = $db->prepare(
            "INSERT INTO application_status_history
                (application_id, changed_by, from_status, to_status, reason)
             VALUES (?, ?, NULL, 'draft', 'Application draft created')"
        );
        $history->bind_param('ii', $applicationId, $userId);
        $history->execute();
        $history->close();

        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'application' => [
            'application_id' => $applicationId,
            'application_number' => $applicationNumber,
            'application_status' => 'draft',
            'program_id' => $programId,
            'period_id' => $periodId,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath application draft endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
