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

function reportQueryDate(string $key): ?string
{
    if (!array_key_exists($key, $_GET)) return null;
    if (!is_string($_GET[$key])) throw new InvalidArgumentException('Report dates must be strings.');
    return $_GET[$key];
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['coordinator', 'approver', 'registrar']);

    $service = new ReportingService();
    $range = $service->normalizeDateRange(reportQueryDate('from'), reportQueryDate('to'));
    $report = $service->build($db, $range['from'], $range['to']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="equipath-report-' . $range['from'] . '-to-' . $range['to'] . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo $service->toCsv($report);
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
    error_log('EquiPath report export failure: ' . get_class($error));
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
