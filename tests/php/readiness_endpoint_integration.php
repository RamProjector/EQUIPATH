<?php
declare(strict_types=1);

function runReadinessEndpoint(): array
{
    http_response_code(200);
    ob_start();
    include dirname(__DIR__, 2) . '/web/api/readiness.php';
    $body = ob_get_clean();

    if (!is_string($body)) {
        throw new RuntimeException('Readiness endpoint produced no response body.');
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Readiness endpoint response must be a JSON object.');
    }

    return ['status' => http_response_code(), 'body' => $decoded, 'raw' => $body];
}

$databaseEnvironment = [];
foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_PORT'] as $name) {
    $databaseEnvironment[$name] = getenv($name);
    putenv($name);
}

$unavailable = runReadinessEndpoint();
if ($unavailable['status'] !== 503
    || ($unavailable['body']['database'] ?? null) !== 'unavailable'
    || ($unavailable['body']['ok'] ?? null) !== false
) {
    fwrite(STDERR, "Readiness endpoint must fail closed when database configuration is missing.\n");
    exit(1);
}

foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_PORT'] as $name) {
    if (str_contains($unavailable['raw'], $name) || str_contains(strtolower($unavailable['raw']), 'password')) {
        fwrite(STDERR, "Readiness response must not disclose configuration details.\n");
        exit(1);
    }
}

foreach ($databaseEnvironment as $name => $value) {
    if ($value !== false) {
        putenv($name . '=' . $value);
    }
}

$connected = runReadinessEndpoint();
if ($connected['status'] !== 200
    || ($connected['body']['database'] ?? null) !== 'connected'
    || ($connected['body']['ok'] ?? null) !== true
) {
    fwrite(STDERR, "Readiness endpoint must confirm a successful SELECT 1 database probe.\n");
    exit(1);
}

echo "Database readiness endpoint integration tests passed.\n";
