<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($applicationId) || $applicationId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_application_details']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['coordinator', 'approver']);

    $applicationQuery = $db->prepare(
        'SELECT application_number, application_status
         FROM applications WHERE application_id = ? LIMIT 1'
    );
    $applicationQuery->bind_param('i', $applicationId);
    $applicationQuery->execute();
    $application = $applicationQuery->get_result()->fetch_assoc();
    $applicationQuery->close();
    if (!is_array($application)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'application_not_found']);
        exit;
    }

    $statement = $db->prepare(
        'SELECT cr.credit_recommendation_id, cr.evaluator_assignment_id,
                cr.course_code, cr.course_title, cr.recommended_credits, cr.rationale, cr.created_at,
                ea.assignment_status
         FROM credit_recommendations cr
         INNER JOIN evaluator_assignments ea ON ea.evaluator_assignment_id = cr.evaluator_assignment_id
         WHERE cr.application_id = ?
         ORDER BY cr.course_code, cr.created_at, cr.credit_recommendation_id
         LIMIT 500'
    );
    $statement->bind_param('i', $applicationId);
    $statement->execute();
    $result = $statement->get_result();
    $recommendations = [];
    while ($row = $result->fetch_assoc()) {
        $recommendations[] = [
            'credit_recommendation_id' => (int) $row['credit_recommendation_id'],
            'evaluator_assignment_id' => (int) $row['evaluator_assignment_id'],
            'assignment_status' => (string) $row['assignment_status'],
            'course_code' => (string) $row['course_code'],
            'course_title' => (string) $row['course_title'],
            'recommended_credits' => (string) $row['recommended_credits'],
            'rationale' => (string) $row['rationale'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    $statement->close();

    echo json_encode([
        'ok' => true,
        'application' => [
            'application_id' => $applicationId,
            'application_number' => (string) $application['application_number'],
            'application_status' => (string) $application['application_status'],
        ],
        'recommendations' => $recommendations,
        'limit' => 500,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath application credit recommendations failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
