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
    $access->requireAnyRole($db, ['registrar']);

    $result = $db->query(
        'SELECT b.integration_batch_id, b.source_system, b.batch_status, b.total_rows,
                b.accepted_rows, b.rejected_rows, b.started_at, b.completed_at, b.created_at,
                COALESCE(JSON_LENGTH(b.error_summary), 0) AS error_count,
                (SELECT COUNT(*) FROM external_record_mappings m
                 WHERE m.integration_batch_id = b.integration_batch_id) AS new_mapping_count
         FROM integration_batches b
         ORDER BY b.created_at DESC, b.integration_batch_id DESC LIMIT 50'
    );
    $batches = [];
    while ($row = $result->fetch_assoc()) {
        $batches[] = [
            'integration_batch_id' => (int) $row['integration_batch_id'],
            'source_system' => (string) $row['source_system'],
            'batch_status' => (string) $row['batch_status'],
            'total_rows' => (int) $row['total_rows'],
            'accepted_rows' => (int) $row['accepted_rows'],
            'rejected_rows' => (int) $row['rejected_rows'],
            'error_count' => (int) $row['error_count'],
            'new_mapping_count' => (int) $row['new_mapping_count'],
            'started_at' => $row['started_at'] === null ? null : (string) $row['started_at'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    echo json_encode(['ok' => true, 'batches' => $batches, 'limit' => 50], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath registrar integration history failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) $db->close();
}
