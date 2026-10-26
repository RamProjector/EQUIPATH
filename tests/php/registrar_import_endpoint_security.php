<?php
declare(strict_types=1);

/**
 * Registrar import HTTP guards. Requests deliberately stop before DB access:
 * no credentials, live records, or configured database are required.
 */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) throw new RuntimeException('Unable to resolve web root.');
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-registrar-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) throw new RuntimeException('Unable to create isolated session directory.');
$server = null;

function registrarRequest(string $url, string $method, string $body = '', array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge(['Connection: close'], $headers)),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 3,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) $status = (int) $match[1];
    }
    return [$status, is_string($response) ? $response : ''];
}

$exitCode = 0;
try {
    $server = proc_open([
        PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port, '-t', $webRoot,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (!is_resource($server)) throw new RuntimeException('Unable to start isolated PHP HTTP server.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    foreach (['registrar-import-preview.php', 'registrar-import-commit.php'] as $endpoint) {
        $url = 'http://127.0.0.1:' . $port . '/api/' . $endpoint;
        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            [$status] = registrarRequest($url, 'GET');
            if ($status === 405) { $ready = true; break; }
            usleep(100000);
        }
        if (!$ready) throw new RuntimeException($endpoint . ' did not become ready.');

        [$methodStatus, $methodBody] = registrarRequest($url, 'GET');
        $methodJson = json_decode($methodBody, true);
        if ($methodStatus !== 405 || ($methodJson['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException($endpoint . ' did not reject GET with HTTP 405.');
        }

        $body = $endpoint === 'registrar-import-preview.php' ? '' : '{"preview_token":"' . str_repeat('a', 64) . '"}';
        $headers = ['Content-Type: application/json', 'Content-Length: ' . strlen($body)];
        [$csrfStatus, $csrfBody] = registrarRequest($url, 'POST', $body, $headers);
        $csrfJson = json_decode($csrfBody, true);
        if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
            throw new RuntimeException($endpoint . ' did not reject POST without a valid CSRF token before DB access.');
        }
    }
    fwrite(STDOUT, "PASS: registrar import HTTP guards (both endpoints reject GET and POST without CSRF).
");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: registrar import HTTP guards — ' . $error->getMessage() . "
");
    $exitCode = 1;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) @unlink($path);
    @rmdir($sessionDir);
}
exit($exitCode);
