<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../src/Database/ConnectionFactory.php';

try {
    $db = ConnectionFactory::createFromEnvironment();
    $db->query('SELECT 1');
    $db->close();

    http_response_code(200);
    $response = [
        'ok' => true,
        'service' => 'equipath-api',
        'database' => 'connected',
    ];
} catch (Throwable $error) {
    // Never return connection details, SQL, credentials, or stack traces.
    http_response_code(503);
    $response = [
        'ok' => false,
        'service' => 'equipath-api',
        'database' => 'unavailable',
    ];
}

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
