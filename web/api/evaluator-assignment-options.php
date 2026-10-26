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
    $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['coordinator']);

    $applicationResult = $db->query(
        "SELECT a.application_id, a.application_number, a.application_status, a.updated_at,
                p.program_code, p.program_name, u.given_name, u.family_name,
                (SELECT COUNT(*) FROM evaluator_assignments ea
                 WHERE ea.application_id = a.application_id) AS assignment_count
         FROM applications a
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         INNER JOIN users u ON u.user_id = a.applicant_user_id
         WHERE a.application_status IN ('eligible', 'under_evaluation')
         ORDER BY a.updated_at ASC, a.application_id ASC
         LIMIT 100"
    );
    $applications = [];
    while ($row = $applicationResult->fetch_assoc()) {
        $applications[] = [
            'application_id' => (int) $row['application_id'],
            'application_number' => (string) $row['application_number'],
            'application_status' => (string) $row['application_status'],
            'updated_at' => (string) $row['updated_at'],
            'program' => [
                'code' => (string) $row['program_code'],
                'name' => (string) $row['program_name'],
            ],
            'applicant' => [
                'given_name' => (string) $row['given_name'],
                'family_name' => (string) $row['family_name'],
            ],
            'assignment_count' => (int) $row['assignment_count'],
        ];
    }

    $evaluatorResult = $db->query(
        "SELECT u.user_id, u.given_name, u.family_name, u.email
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.user_id
         INNER JOIN roles r ON r.role_id = ur.role_id
         WHERE u.account_status = 'active' AND r.role_key = 'evaluator'
         ORDER BY u.family_name, u.given_name, u.email
         LIMIT 500"
    );
    $evaluators = [];
    while ($row = $evaluatorResult->fetch_assoc()) {
        $evaluators[] = [
            'user_id' => (int) $row['user_id'],
            'given_name' => (string) $row['given_name'],
            'family_name' => (string) $row['family_name'],
            'email' => (string) $row['email'],
        ];
    }

    $assignmentResult = $db->query(
        "SELECT ea.evaluator_assignment_id, ea.application_id, ea.evaluator_user_id,
                ea.assignment_status, ea.assigned_at, u.given_name, u.family_name, u.email
         FROM evaluator_assignments ea
         INNER JOIN applications a ON a.application_id = ea.application_id
         INNER JOIN users u ON u.user_id = ea.evaluator_user_id
         WHERE a.application_status IN ('eligible', 'under_evaluation')
         ORDER BY ea.assigned_at, ea.evaluator_assignment_id
         LIMIT 1000"
    );
    $assignments = [];
    while ($row = $assignmentResult->fetch_assoc()) {
        $assignments[] = [
            'evaluator_assignment_id' => (int) $row['evaluator_assignment_id'],
            'application_id' => (int) $row['application_id'],
            'evaluator_user_id' => (int) $row['evaluator_user_id'],
            'assignment_status' => (string) $row['assignment_status'],
            'assigned_at' => (string) $row['assigned_at'],
            'evaluator' => [
                'given_name' => (string) $row['given_name'],
                'family_name' => (string) $row['family_name'],
                'email' => (string) $row['email'],
            ],
        ];
    }

    echo json_encode([
        'ok' => true,
        'applications' => $applications,
        'evaluators' => $evaluators,
        'assignments' => $assignments,
        'application_limit' => 100,
        'evaluator_limit' => 500,
        'assignment_limit' => 1000,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath evaluator assignment options endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
