<?php
declare(strict_types=1);

/**
 * HTTP-level upload endpoint guard tests.
 * Uses PHP's built-in server and synthetic multipart bytes; no real applicant
 * data or database connection is needed because requests fail before DB access.
 */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}

$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-upload-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;

function requestUploadEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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

$exitCode = 0;

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

    $url = 'http://127.0.0.1:' . $port . '/api/upload-document.php';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestUploadEndpoint($url, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for upload endpoint tests.');
    }

    [$methodStatus, $methodBody] = requestUploadEndpoint($url, 'GET');
    $methodJson = json_decode($methodBody, true);
    if ($methodStatus !== 405 || ($methodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Upload endpoint did not reject GET with HTTP 405.');
    }

    $boundary = '----EquiPathTest' . bin2hex(random_bytes(8));
    $multipart = '--' . $boundary . "\r\n" .
        'Content-Disposition: form-data; name="application_id"' . "\r\n\r\n" .
        '1' . "\r\n" .
        '--' . $boundary . "\r\n" .
        'Content-Disposition: form-data; name="document_type"' . "\r\n\r\n" .
        'portfolio' . "\r\n" .
        '--' . $boundary . "\r\n" .
        'Content-Disposition: form-data; name="document"; filename="synthetic.pdf"' . "\r\n" .
        "Content-Type: application/pdf\r\n\r\n" .
        "%PDF-1.7\nsynthetic test fixture\n%%EOF\n" . "\r\n" .
        '--' . $boundary . "--\r\n";

    [$csrfStatus, $csrfBody] = requestUploadEndpoint($url, 'POST', $multipart, [
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Content-Length: ' . strlen($multipart),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Multipart upload without a valid CSRF token was not rejected before authentication/database access.');
    }

    fwrite(STDOUT, "PASS: upload endpoint HTTP guards (GET rejected; multipart POST without CSRF token denied).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: upload endpoint HTTP guards — ' . $error->getMessage() . "\n");
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
