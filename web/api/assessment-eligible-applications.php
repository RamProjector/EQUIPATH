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

    $result = $db->query(
        "SELECT a.application_id, a.application_number, a.application_status,
                a.updated_at, p.program_code, p.program_name
         FROM applications a
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         WHERE a.application_status IN ('eligible', 'assessment_scheduled')
            OR (
                a.application_status = 'under_evaluation'
                AND EXISTS (
                    SELECT 1 FROM evaluator_assignments ea
                    WHERE ea.application_id = a.application_id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM evaluator_assignments ea
                    WHERE ea.application_id = a.application_id
                      AND ea.assignment_status <> 'submitted'
                )
            )
         ORDER BY a.updated_at DESC, a.application_id DESC
         LIMIT 100"
    );
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $applications[] = [
            'application_id' => (int) $row['application_id'],
            'application_number' => (string) $row['application_number'],
            'application_status' => (string) $row['application_status'],
            'updated_at' => (string) $row['updated_at'],
            'program' => [
                'code' => (string) $row['program_code'],
                'name' => (string) $row['program_name'],
            ],
        ];
    }

    echo json_encode(['ok' => true, 'applications' => $applications, 'limit' => 100], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath assessment candidate endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
