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
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $roles = $access->requireAnyRole($db, ['applicant', 'coordinator', 'evaluator']);

    $canManage = in_array('coordinator', $roles, true);
    $canViewOwn = in_array('applicant', $roles, true);
    $canViewAssigned = in_array('evaluator', $roles, true);

    $statement = $db->prepare(
        "SELECT e.assessment_event_id, e.application_id, e.assessment_type,
                e.starts_at, e.ends_at, e.location_or_link, e.event_status, e.notes,
                a.application_number, a.application_status,
                p.program_code, p.program_name,
                ar.outcome, ar.score, ar.feedback, ar.recorded_at
         FROM assessment_events e
         INNER JOIN applications a ON a.application_id = e.application_id
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         LEFT JOIN assessment_results ar ON ar.assessment_event_id = e.assessment_event_id
         WHERE (? = 1
             OR (? = 1 AND a.applicant_user_id = ?)
             OR (? = 1 AND EXISTS (
                 SELECT 1 FROM evaluator_assignments ea
                 WHERE ea.application_id = a.application_id AND ea.evaluator_user_id = ?
             )))
         ORDER BY e.starts_at DESC, e.assessment_event_id DESC
         LIMIT 100"
    );
    $coordinatorFlag = $canManage ? 1 : 0;
    $applicantFlag = $canViewOwn ? 1 : 0;
    $evaluatorFlag = $canViewAssigned ? 1 : 0;
    $statement->bind_param(
        'iiiii',
        $coordinatorFlag,
        $applicantFlag,
        $userId,
        $evaluatorFlag,
        $userId
    );
    $statement->execute();
    $result = $statement->get_result();
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'assessment_event_id' => (int) $row['assessment_event_id'],
            'application_id' => (int) $row['application_id'],
            'application_number' => (string) $row['application_number'],
            'application_status' => (string) $row['application_status'],
            'assessment_type' => (string) $row['assessment_type'],
            'starts_at' => (string) $row['starts_at'],
            'ends_at' => (string) $row['ends_at'],
            'location_or_link' => $row['location_or_link'] === null ? null : (string) $row['location_or_link'],
            'event_status' => (string) $row['event_status'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'program' => [
                'code' => (string) $row['program_code'],
                'name' => (string) $row['program_name'],
            ],
            'result' => $row['outcome'] === null ? null : [
                'outcome' => (string) $row['outcome'],
                'score' => $row['score'] === null ? null : (string) $row['score'],
                'feedback' => $row['feedback'] === null ? null : (string) $row['feedback'],
                'recorded_at' => (string) $row['recorded_at'],
            ],
        ];
    }
    $statement->close();

    echo json_encode([
        'ok' => true,
        'can_manage' => $canManage,
        'assessments' => $events,
        'limit' => 100,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath assessment list endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
