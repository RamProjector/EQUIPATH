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
$assignmentId = filter_input(INPUT_GET, 'evaluator_assignment_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($assignmentId) || $assignmentId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_assignment_details']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $roles = $access->requireAnyRole($db, ['evaluator', 'coordinator', 'approver']);
    $wideView = in_array('coordinator', $roles, true) || in_array('approver', $roles, true);
    $isEvaluator = in_array('evaluator', $roles, true);

    $assignmentQuery = $db->prepare(
        'SELECT ea.evaluator_assignment_id, ea.application_id, ea.evaluator_user_id, ea.assignment_status,
                a.application_number, a.application_status
         FROM evaluator_assignments ea
         INNER JOIN applications a ON a.application_id = ea.application_id
         WHERE ea.evaluator_assignment_id = ?
           AND (? = 1 OR (? = 1 AND ea.evaluator_user_id = ?))
         LIMIT 1'
    );
    $wideFlag = $wideView ? 1 : 0;
    $evaluatorFlag = $isEvaluator ? 1 : 0;
    $assignmentQuery->bind_param('iiii', $assignmentId, $wideFlag, $evaluatorFlag, $userId);
    $assignmentQuery->execute();
    $assignment = $assignmentQuery->get_result()->fetch_assoc();
    $assignmentQuery->close();
    if (!is_array($assignment)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'assignment_not_found']);
        exit;
    }

    $statement = $db->prepare(
        'SELECT credit_recommendation_id, course_code, course_title, recommended_credits, rationale, created_at
         FROM credit_recommendations
         WHERE evaluator_assignment_id = ?
         ORDER BY course_code, credit_recommendation_id
         LIMIT 100'
    );
    $statement->bind_param('i', $assignmentId);
    $statement->execute();
    $result = $statement->get_result();
    $recommendations = [];
    while ($row = $result->fetch_assoc()) {
        $recommendations[] = [
            'credit_recommendation_id' => (int) $row['credit_recommendation_id'],
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
        'assignment' => [
            'evaluator_assignment_id' => (int) $assignment['evaluator_assignment_id'],
            'application_id' => (int) $assignment['application_id'],
            'application_number' => (string) $assignment['application_number'],
            'application_status' => (string) $assignment['application_status'],
            'assignment_status' => (string) $assignment['assignment_status'],
        ],
        'can_submit' => $isEvaluator &&
            (int) $assignment['evaluator_user_id'] === $userId &&
            (string) $assignment['assignment_status'] === 'submitted' &&
            in_array((string) $assignment['application_status'], ['under_evaluation', 'assessment_scheduled', 'awaiting_decision'], true),
        'recommendations' => $recommendations,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath credit recommendation list failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
