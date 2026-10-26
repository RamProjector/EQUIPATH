<?php
declare(strict_types=1);

/**
 * HTTP-level method, authentication and CSRF guards for notification endpoints.
 * No database connection or real user account is needed for these early guards.
 */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}

$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-notification-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestNotificationEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
{
    $headerLines = array_merge(['Connection: close'], $headers);
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'content' => $body ?? '',
        'ignore_errors' => true,
        'timeout' => 3,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
            $status = (int) $match[1];
        }
    }
    return [$status, is_string($response) ? $response : ''];
}

try {
    $command = [
        PHP_BINARY, '-d', 'session.save_path=' . $sessionDir,
        '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port,
        '-t', $webRoot,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $server = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start isolated PHP HTTP server.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $base = 'http://127.0.0.1:' . $port . '/api/';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestNotificationEndpoint($base . 'mark-notification-read.php', 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for notification endpoint tests.');
    }

    [$listMethodStatus, $listMethodBody] = requestNotificationEndpoint($base . 'notifications.php', 'POST');
    $listMethodJson = json_decode($listMethodBody, true);
    if ($listMethodStatus !== 405 || ($listMethodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Notification inbox did not reject POST with HTTP 405.');
    }

    [$unauthenticatedStatus, $unauthenticatedBody] = requestNotificationEndpoint($base . 'notifications.php', 'GET');
    $unauthenticatedJson = json_decode($unauthenticatedBody, true);
    if ($unauthenticatedStatus !== 401 || ($unauthenticatedJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Notification inbox did not reject an unauthenticated GET with HTTP 401.');
    }

    [$markMethodStatus, $markMethodBody] = requestNotificationEndpoint($base . 'mark-notification-read.php', 'GET');
    $markMethodJson = json_decode($markMethodBody, true);
    if ($markMethodStatus !== 405 || ($markMethodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Mark-read endpoint did not reject GET with HTTP 405.');
    }

    $body = '{"notification_id":1}';
    [$csrfStatus, $csrfBody] = requestNotificationEndpoint($base . 'mark-notification-read.php', 'POST', $body, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Mark-read POST without a valid CSRF token was not rejected before authentication/database access.');
    }

    fwrite(STDOUT, "PASS: notification endpoint HTTP guards (method checks, unauthenticated inbox denial, CSRF denial).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: notification endpoint HTTP guards — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($sessionDir);
}

exit($exitCode);
