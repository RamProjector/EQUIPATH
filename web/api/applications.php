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

    // Role membership and active status come from the database, never from
    // client input or session role claims.
    $roles = $access->requireAnyRole($db, ['applicant', 'evaluator']);
    $applications = [];

    if (in_array('applicant', $roles, true)) {
        $statement = $db->prepare(
            'SELECT a.application_id, a.application_number, a.application_status,
                    a.submitted_at, a.created_at, a.updated_at, p.program_code, p.program_name,
                    "applicant" AS access_type
             FROM applications a
             INNER JOIN academic_programs p ON p.program_id = a.program_id
             WHERE a.applicant_user_id = ?
             ORDER BY a.updated_at DESC, a.application_id DESC
             LIMIT 50'
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['application_id'] = (int) $row['application_id'];
            $applications[$row['application_id']] = $row;
        }
        $statement->close();
    }

    if (in_array('evaluator', $roles, true)) {
        $statement = $db->prepare(
            "SELECT a.application_id, a.application_number, a.application_status,
                    a.submitted_at, a.created_at, a.updated_at, p.program_code, p.program_name,
                    'evaluator' AS access_type
             FROM evaluator_assignments ea
             INNER JOIN applications a ON a.application_id = ea.application_id
             INNER JOIN academic_programs p ON p.program_id = a.program_id
             WHERE ea.evaluator_user_id = ?
               AND ea.assignment_status IN ('assigned', 'in_progress', 'submitted')
             ORDER BY a.updated_at DESC, a.application_id DESC
             LIMIT 50"
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['application_id'] = (int) $row['application_id'];
            $applications[$row['application_id']] ??= $row;
        }
        $statement->close();
    }

    // Defense in depth: recheck every returned resource against the shared
    // ownership/assignment policy before it leaves the API.
    foreach ($applications as $applicationId => $row) {
        $access->requireApplicationAccess($db, (int) $applicationId);
    }

    $items = array_values($applications);
    usort($items, static fn(array $left, array $right): int =>
        strcmp(
            (string) ($right['updated_at'] ?? $right['created_at']),
            (string) ($left['updated_at'] ?? $left['created_at'])
        )
    );

    echo json_encode([
        'ok' => true,
        'applications' => array_map(static fn(array $row): array => [
            'application_id' => $row['application_id'],
            'application_number' => $row['application_number'],
            'application_status' => $row['application_status'],
            'submitted_at' => $row['submitted_at'],
            'created_at' => $row['created_at'],
            'program' => [
                'code' => $row['program_code'],
                'name' => $row['program_name'],
            ],
            'access_type' => $row['access_type'],
        ], $items),
        'limit' => 50,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath applications endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
