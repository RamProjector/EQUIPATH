<?php
declare(strict_types=1);

/** HTTP method, CSRF and authentication guards for accreditation endpoints. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-accreditation-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestAccreditationEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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
    $decisionUrl = $base . 'record-accreditation-decision.php';
    $candidatesUrl = $base . 'accreditation-candidates.php';
    $historyUrl = $base . 'accreditation-decisions.php?application_id=1';
    $recommendationsUrl = $base . 'application-credit-recommendations.php?application_id=1';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestAccreditationEndpoint($decisionUrl, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for accreditation endpoint tests.');
    }

    foreach ([$decisionUrl => 'decision submission'] as $url => $label) {
        [$status, $body] = requestAccreditationEndpoint($url, 'GET');
        $json = json_decode($body, true);
        if ($status !== 405 || ($json['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject GET with HTTP 405.');
        }
    }
    foreach ([$candidatesUrl => 'decision candidate list', $historyUrl => 'decision history', $recommendationsUrl => 'application recommendations'] as $url => $label) {
        [$status, $body] = requestAccreditationEndpoint($url, 'POST');
        $json = json_decode($body, true);
        if ($status !== 405 || ($json['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject POST with HTTP 405.');
        }
    }

    $decisionBody = json_encode([
        'application_id' => 1,
        'decision' => 'rejected',
        'rationale' => 'Test request',
        'approved_credits' => [],
    ], JSON_THROW_ON_ERROR);
    [$csrfStatus, $csrfBody] = requestAccreditationEndpoint($decisionUrl, 'POST', $decisionBody, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($decisionBody),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Accreditation decision POST without CSRF was not denied before authentication/database access.');
    }

    foreach ([$candidatesUrl => 'decision candidate list', $historyUrl => 'decision history', $recommendationsUrl => 'application recommendations'] as $url => $label) {
        [$status, $body] = requestAccreditationEndpoint($url, 'GET');
        $json = json_decode($body, true);
        if ($status !== 401 || ($json['error'] ?? null) !== 'authentication_required') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject unauthenticated GET with HTTP 401.');
        }
    }

    fwrite(STDOUT, "PASS: accreditation HTTP guards (method restrictions, CSRF rejection and authentication denial).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: accreditation endpoint HTTP guards — ' . $error->getMessage() . "\n");
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
