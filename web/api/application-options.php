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
    $access->requireAnyRole($db, ['applicant']);

    $programResult = $db->query(
        'SELECT program_id, program_code, program_name
         FROM academic_programs WHERE is_active = TRUE
         ORDER BY program_name, program_code LIMIT 200'
    );
    $programs = [];
    while ($row = $programResult->fetch_assoc()) {
        $programId = (int) $row['program_id'];
        $programs[$programId] = [
            'program_id' => $programId,
            'program_code' => (string) $row['program_code'],
            'program_name' => (string) $row['program_name'],
            'requirements' => [],
        ];
    }

    $requirements = $db->query(
        'SELECT pr.program_id, pr.requirement_key, pr.requirement_name, pr.description,
                pr.is_required, pr.max_file_size_bytes
         FROM program_requirements pr
         INNER JOIN academic_programs p ON p.program_id = pr.program_id
         WHERE p.is_active = TRUE AND pr.is_active = TRUE
         ORDER BY pr.program_id, pr.is_required DESC, pr.requirement_name
         LIMIT 1000'
    );
    while ($row = $requirements->fetch_assoc()) {
        $programId = (int) $row['program_id'];
        if (!isset($programs[$programId])) {
            continue;
        }
        $programs[$programId]['requirements'][] = [
            'requirement_key' => (string) $row['requirement_key'],
            'requirement_name' => (string) $row['requirement_name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'is_required' => (bool) $row['is_required'],
            'max_file_size_bytes' => $row['max_file_size_bytes'] === null ? null : (int) $row['max_file_size_bytes'],
        ];
    }

    $periodResult = $db->query(
        "SELECT period_id, period_name, opens_at, closes_at
         FROM application_periods
         WHERE period_status = 'open'
           AND CURRENT_TIMESTAMP BETWEEN opens_at AND closes_at
         ORDER BY opens_at DESC, period_id DESC LIMIT 100"
    );
    $periods = [];
    while ($row = $periodResult->fetch_assoc()) {
        $periods[] = [
            'period_id' => (int) $row['period_id'],
            'period_name' => (string) $row['period_name'],
            'opens_at' => (string) $row['opens_at'],
            'closes_at' => (string) $row['closes_at'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'programs' => array_values($programs),
        'open_periods' => $periods,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath application options endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
