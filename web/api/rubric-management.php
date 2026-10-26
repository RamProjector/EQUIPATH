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

    $programResult = $db->query(
        'SELECT program_id, program_code, program_name
         FROM academic_programs WHERE is_active = TRUE
         ORDER BY program_name, program_code LIMIT 200'
    );
    $programs = [];
    while ($row = $programResult->fetch_assoc()) {
        $programs[(int) $row['program_id']] = [
            'program_id' => (int) $row['program_id'],
            'program_code' => (string) $row['program_code'],
            'program_name' => (string) $row['program_name'],
        ];
    }

    $rubricResult = $db->query(
        'SELECT rubric_id, program_id, rubric_name, version_number, is_active, created_at
         FROM evaluation_rubrics
         ORDER BY program_id, version_number DESC, rubric_id DESC LIMIT 500'
    );
    $rubrics = [];
    while ($row = $rubricResult->fetch_assoc()) {
        $rubrics[(int) $row['rubric_id']] = [
            'rubric_id' => (int) $row['rubric_id'],
            'program_id' => (int) $row['program_id'],
            'rubric_name' => (string) $row['rubric_name'],
            'version_number' => (int) $row['version_number'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) $row['created_at'],
            'criteria' => [],
        ];
    }

    $criteriaResult = $db->query(
        'SELECT c.rubric_id, c.criterion_id, c.criterion_code, c.criterion_name,
                c.description, c.max_score, c.weight, c.sort_order
         FROM rubric_criteria c
         INNER JOIN evaluation_rubrics r ON r.rubric_id = c.rubric_id
         ORDER BY c.rubric_id, c.sort_order, c.criterion_id LIMIT 5000'
    );
    while ($row = $criteriaResult->fetch_assoc()) {
        $rubricId = (int) $row['rubric_id'];
        if (!isset($rubrics[$rubricId])) continue;
        $rubrics[$rubricId]['criteria'][] = [
            'criterion_id' => (int) $row['criterion_id'],
            'criterion_code' => (string) $row['criterion_code'],
            'criterion_name' => (string) $row['criterion_name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'max_score' => (string) $row['max_score'],
            'weight' => (string) $row['weight'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }

    $activeCounts = [];
    foreach ($rubrics as $rubric) {
        if ($rubric['is_active']) {
            $activeCounts[$rubric['program_id']] = ($activeCounts[$rubric['program_id']] ?? 0) + 1;
        }
    }
    foreach ($programs as $programId => &$program) {
        $program['active_rubric_count'] = $activeCounts[$programId] ?? 0;
    }
    unset($program);

    echo json_encode([
        'ok' => true,
        'programs' => array_values($programs),
        'rubrics' => array_values($rubrics),
        'program_limit' => 200,
        'rubric_limit' => 500,
        'criterion_limit' => 5000,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath rubric management read failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
