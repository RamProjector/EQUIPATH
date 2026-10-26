<?php
declare(strict_types=1);

/** HTTP method and CSRF guard checks for the criterion scoring endpoint. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-scoring-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestScoringEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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

    $url = 'http://127.0.0.1:' . $port . '/api/score-criterion.php';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestScoringEndpoint($url, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for scoring endpoint tests.');
    }

    [$methodStatus, $methodBody] = requestScoringEndpoint($url, 'GET');
    $methodJson = json_decode($methodBody, true);
    if ($methodStatus !== 405 || ($methodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Scoring endpoint did not reject GET with HTTP 405.');
    }

    $body = '{"evaluator_assignment_id":1,"criterion_id":1,"score":5}';
    [$csrfStatus, $csrfBody] = requestScoringEndpoint($url, 'POST', $body, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Scoring POST without a valid CSRF token was not rejected before authentication/database access.');
    }

    fwrite(STDOUT, "PASS: evaluation scoring endpoint HTTP guards (GET rejected; POST without CSRF token denied).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: evaluation scoring endpoint HTTP guards — ' . $error->getMessage() . "\n");
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
