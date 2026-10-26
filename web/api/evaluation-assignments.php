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

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $evaluatorUserId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['evaluator']);

    $statement = $db->prepare(
        "SELECT ea.evaluator_assignment_id, ea.assignment_status, ea.assigned_at, ea.submitted_at,
                a.application_id, a.application_number, a.application_status,
                a.updated_at, p.program_code, p.program_name,
                (SELECT COUNT(*) FROM evaluation_rubrics r
                 WHERE r.program_id = a.program_id AND r.is_active = 1) AS active_rubric_count,
                (SELECT r.rubric_id FROM evaluation_rubrics r
                 WHERE r.program_id = a.program_id AND r.is_active = 1
                 ORDER BY r.version_number DESC, r.rubric_id DESC LIMIT 1) AS active_rubric_id
         FROM evaluator_assignments ea
         INNER JOIN applications a ON a.application_id = ea.application_id
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         WHERE ea.evaluator_user_id = ?
           AND ea.assignment_status IN ('assigned', 'in_progress', 'submitted')
           AND a.application_status = 'under_evaluation'
         ORDER BY a.updated_at DESC, ea.evaluator_assignment_id DESC
         LIMIT 50"
    );
    $statement->bind_param('i', $evaluatorUserId);
    $statement->execute();
    $result = $statement->get_result();
    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $assignments[] = [
            'evaluator_assignment_id' => (int) $row['evaluator_assignment_id'],
            'application_id' => (int) $row['application_id'],
            'application_number' => (string) $row['application_number'],
            'application_status' => (string) $row['application_status'],
            'assignment_status' => (string) $row['assignment_status'],
            'assigned_at' => (string) $row['assigned_at'],
            'submitted_at' => $row['submitted_at'] === null ? null : (string) $row['submitted_at'],
            'program' => [
                'code' => (string) $row['program_code'],
                'name' => (string) $row['program_name'],
            ],
            'active_rubric_id' => $row['active_rubric_id'] === null ? null : (int) $row['active_rubric_id'],
            'active_rubric_count' => (int) $row['active_rubric_count'],
        ];
    }
    $statement->close();

    echo json_encode(['ok' => true, 'assignments' => $assignments, 'limit' => 50], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath evaluator assignment queue failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
