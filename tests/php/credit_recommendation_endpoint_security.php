<?php
declare(strict_types=1);

/** HTTP method, CSRF and authentication guards for credit recommendation APIs. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-credit-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestCreditEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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
    $submitUrl = $base . 'submit-credit-recommendations.php';
    $listUrl = $base . 'credit-recommendations.php?evaluator_assignment_id=1';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestCreditEndpoint($submitUrl, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for credit recommendation endpoint tests.');
    }

    [$methodStatus, $methodBody] = requestCreditEndpoint($submitUrl, 'GET');
    $methodJson = json_decode($methodBody, true);
    if ($methodStatus !== 405 || ($methodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Credit recommendation submission endpoint did not reject GET with HTTP 405.');
    }

    $body = '{"evaluator_assignment_id":1,"recommendations":[]}';
    [$csrfStatus, $csrfBody] = requestCreditEndpoint($submitUrl, 'POST', $body, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Credit recommendation POST without CSRF was not rejected before authentication/database access.');
    }

    [$listMethodStatus, $listMethodBody] = requestCreditEndpoint($listUrl, 'POST');
    $listMethodJson = json_decode($listMethodBody, true);
    if ($listMethodStatus !== 405 || ($listMethodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Credit recommendation listing endpoint did not reject POST with HTTP 405.');
    }

    [$listAuthStatus, $listAuthBody] = requestCreditEndpoint($listUrl, 'GET');
    $listAuthJson = json_decode($listAuthBody, true);
    if ($listAuthStatus !== 401 || ($listAuthJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Credit recommendation listing endpoint did not reject unauthenticated GET with HTTP 401.');
    }

    fwrite(STDOUT, "PASS: credit recommendation HTTP guards (method restrictions, CSRF rejection and authentication denial).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: credit recommendation HTTP guards — ' . $error->getMessage() . "\n");
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
