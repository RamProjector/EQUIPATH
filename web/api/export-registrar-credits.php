<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';
require_once dirname(__DIR__) . '/src/Reporting/ReportingService.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

function registrarExportDate(string $key): ?string
{
    if (!array_key_exists($key, $_GET)) return null;
    if (!is_string($_GET[$key])) throw new InvalidArgumentException('Report dates must be strings.');
    return $_GET[$key];
}

function registrarCsvCell(string $value): string
{
    $trimmed = ltrim($value);
    if ($trimmed !== '' && preg_match('/^[=+\-@]/', $trimmed)) return "'" . $value;
    return $value;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['registrar']);

    $requestedFrom = registrarExportDate('from');
    $requestedTo = registrarExportDate('to');
    $allCurrentCredits = ($requestedFrom === null || $requestedFrom === '') && ($requestedTo === null || $requestedTo === '');
    $range = $allCurrentCredits ? null : (new ReportingService())->normalizeDateRange(
        $requestedFrom === '' ? null : $requestedFrom,
        $requestedTo === '' ? null : $requestedTo
    );
    $dateFilter = $allCurrentCredits ? '' : ' AND d.decided_at >= ? AND d.decided_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $sql = "SELECT a.application_number, p.program_code, c.course_code, c.course_title,
                   c.approved_credits, d.decided_at
            FROM accreditation_decisions d
            INNER JOIN applications a ON a.application_id = d.application_id
            INNER JOIN academic_programs p ON p.program_id = a.program_id
            INNER JOIN accredited_credits c ON c.accreditation_decision_id = d.accreditation_decision_id
            WHERE d.decision = 'approved'
              AND NOT EXISTS (
                SELECT 1 FROM accreditation_decisions successor
                WHERE successor.supersedes_decision_id = d.accreditation_decision_id
              )" . $dateFilter . "
            ORDER BY a.application_number, c.course_code, c.accredited_credit_id";
    $statement = $db->prepare($sql);
    if (!$allCurrentCredits) $statement->bind_param('ss', $range['from'], $range['to']);
    $statement->execute();
    $result = $statement->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="equipath-approved-credits-' . $range['from'] . '-to-' . $range['to'] . '.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) throw new RuntimeException('Unable to stream registrar export.');
    fputcsv($output, ['application_number', 'program_code', 'course_code', 'course_title', 'approved_credits', 'decision_at'], ',', '"', '');
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            registrarCsvCell((string) $row['application_number']),
            registrarCsvCell((string) $row['program_code']),
            registrarCsvCell((string) $row['course_code']),
            registrarCsvCell((string) $row['course_title']),
            number_format((float) $row['approved_credits'], 2, '.', ''),
            (string) $row['decided_at'],
        ], ',', '"', '');
    }
    fclose($output);
    $statement->close();
} catch (InvalidArgumentException $error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_report_range']);
} catch (AuthenticationRequiredException $error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath registrar approved-credit export failure: ' . get_class($error));
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) $db->close();
}
