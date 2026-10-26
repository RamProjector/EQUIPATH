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
    $evaluatorUserId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['evaluator']);

    $assignmentQuery = $db->prepare(
        "SELECT ea.evaluator_assignment_id, ea.assignment_status,
                a.application_id, a.application_number, a.application_status, a.program_id,
                p.program_code, p.program_name
         FROM evaluator_assignments ea
         INNER JOIN applications a ON a.application_id = ea.application_id
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         WHERE ea.evaluator_assignment_id = ? AND ea.evaluator_user_id = ?
         LIMIT 1"
    );
    $assignmentQuery->bind_param('ii', $assignmentId, $evaluatorUserId);
    $assignmentQuery->execute();
    $assignment = $assignmentQuery->get_result()->fetch_assoc();
    $assignmentQuery->close();

    if (!is_array($assignment)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'assignment_not_found']);
        exit;
    }
    if (!in_array((string) $assignment['assignment_status'], ['assigned', 'in_progress'], true) ||
        (string) $assignment['application_status'] !== 'under_evaluation') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'evaluation_not_editable']);
        exit;
    }

    $programId = (int) $assignment['program_id'];
    $rubricQuery = $db->prepare(
        'SELECT rubric_id, rubric_name, version_number
         FROM evaluation_rubrics
         WHERE program_id = ? AND is_active = 1
         ORDER BY version_number DESC, rubric_id DESC'
    );
    $rubricQuery->bind_param('i', $programId);
    $rubricQuery->execute();
    $rubricResult = $rubricQuery->get_result();
    $activeRubrics = [];
    while ($row = $rubricResult->fetch_assoc()) {
        $activeRubrics[] = [
            'rubric_id' => (int) $row['rubric_id'],
            'rubric_name' => (string) $row['rubric_name'],
            'version_number' => (int) $row['version_number'],
        ];
    }
    $rubricQuery->close();

    if (count($activeRubrics) !== 1) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => count($activeRubrics) === 0 ? 'active_rubric_unavailable' : 'active_rubric_ambiguous',
        ]);
        exit;
    }
    $rubric = $activeRubrics[0];
    $rubricId = $rubric['rubric_id'];

    $existingQuery = $db->prepare(
        'SELECT DISTINCT rc.rubric_id
         FROM evaluation_scores es
         INNER JOIN rubric_criteria rc ON rc.criterion_id = es.criterion_id
         WHERE es.evaluator_assignment_id = ?'
    );
    $existingQuery->bind_param('i', $assignmentId);
    $existingQuery->execute();
    $existingResult = $existingQuery->get_result();
    while ($existing = $existingResult->fetch_assoc()) {
        if ((int) $existing['rubric_id'] !== $rubricId) {
            $existingQuery->close();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'rubric_version_changed']);
            exit;
        }
    }
    $existingQuery->close();

    $criteriaQuery = $db->prepare(
        'SELECT rc.criterion_id, rc.criterion_code, rc.criterion_name, rc.description,
                rc.max_score, rc.weight, rc.sort_order, es.score, es.feedback
         FROM rubric_criteria rc
         LEFT JOIN evaluation_scores es
           ON es.criterion_id = rc.criterion_id AND es.evaluator_assignment_id = ?
         WHERE rc.rubric_id = ?
         ORDER BY rc.sort_order, rc.criterion_id'
    );
    $criteriaQuery->bind_param('ii', $assignmentId, $rubricId);
    $criteriaQuery->execute();
    $criteriaResult = $criteriaQuery->get_result();
    $criteria = [];
    while ($row = $criteriaResult->fetch_assoc()) {
        $criteria[] = [
            'criterion_id' => (int) $row['criterion_id'],
            'criterion_code' => (string) $row['criterion_code'],
            'criterion_name' => (string) $row['criterion_name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'max_score' => (string) $row['max_score'],
            'weight' => (string) $row['weight'],
            'sort_order' => (int) $row['sort_order'],
            'score' => $row['score'] === null ? null : (string) $row['score'],
            'feedback' => $row['feedback'] === null ? '' : (string) $row['feedback'],
        ];
    }
    $criteriaQuery->close();

    if ($criteria === []) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'rubric_has_no_criteria']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'assignment' => [
            'evaluator_assignment_id' => $assignmentId,
            'application_id' => (int) $assignment['application_id'],
            'application_number' => (string) $assignment['application_number'],
            'program' => [
                'code' => (string) $assignment['program_code'],
                'name' => (string) $assignment['program_name'],
            ],
        ],
        'rubric' => $rubric,
        'criteria' => $criteria,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath evaluator rubric endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
