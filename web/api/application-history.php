<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';
require_once dirname(__DIR__) . '/src/Applications/ApplicationHistoryService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$applicationValue = $_GET['application_id'] ?? null;
if (!is_string($applicationValue) || !ctype_digit($applicationValue) || (int) $applicationValue < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_application_details']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $access->requireAnyRole($db, ['applicant']);

    $history = (new ApplicationHistoryService())->getForApplicant($db, $userId, (int) $applicationValue);
    echo json_encode(['ok' => true, 'history' => $history], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_application_details']);
} catch (DomainException $error) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'application_not_found']);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath applicant history endpoint failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
