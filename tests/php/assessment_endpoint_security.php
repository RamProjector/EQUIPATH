<?php
declare(strict_types=1);

/** HTTP method, CSRF and authentication guards for assessment endpoints. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-assessment-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestAssessmentEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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
    $scheduleUrl = $base . 'create-assessment.php';
    $resultUrl = $base . 'record-assessment-result.php';
    $listUrl = $base . 'assessments.php';
    $candidatesUrl = $base . 'assessment-eligible-applications.php';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestAssessmentEndpoint($scheduleUrl, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for assessment endpoint tests.');
    }

    foreach ([$scheduleUrl => 'create assessment', $resultUrl => 'record assessment result', $listUrl => 'assessment list', $candidatesUrl => 'assessment candidate list'] as $url => $label) {
        [$status, $body] = requestAssessmentEndpoint($url, 'POST');
        $json = json_decode($body, true);
        if ($status !== 405 || ($json['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject POST with HTTP 405.');
        }
    }

    $scheduleBody = json_encode([
        'application_id' => 1,
        'assessment_type' => 'interview',
        'starts_at' => '2099-01-01T10:00',
        'ends_at' => '2099-01-01T11:00',
    ], JSON_THROW_ON_ERROR);
    [$scheduleCsrfStatus, $scheduleCsrfBody] = requestAssessmentEndpoint($scheduleUrl, 'POST', $scheduleBody, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($scheduleBody),
    ]);
    $scheduleCsrfJson = json_decode($scheduleCsrfBody, true);
    if ($scheduleCsrfStatus !== 403 || ($scheduleCsrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Assessment scheduling POST without CSRF was not denied before authentication/database access.');
    }

    $resultBody = '{"assessment_event_id":1,"outcome":"passed"}';
    [$resultCsrfStatus, $resultCsrfBody] = requestAssessmentEndpoint($resultUrl, 'POST', $resultBody, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($resultBody),
    ]);
    $resultCsrfJson = json_decode($resultCsrfBody, true);
    if ($resultCsrfStatus !== 403 || ($resultCsrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Assessment result POST without CSRF was not denied before authentication/database access.');
    }

    [$listAuthStatus, $listAuthBody] = requestAssessmentEndpoint($listUrl, 'GET');
    $listAuthJson = json_decode($listAuthBody, true);
    if ($listAuthStatus !== 401 || ($listAuthJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Assessment list did not reject unauthenticated GET with HTTP 401.');
    }

    [$candidateAuthStatus, $candidateAuthBody] = requestAssessmentEndpoint($candidatesUrl, 'GET');
    $candidateAuthJson = json_decode($candidateAuthBody, true);
    if ($candidateAuthStatus !== 401 || ($candidateAuthJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Assessment candidate list did not reject unauthenticated GET with HTTP 401.');
    }

    fwrite(STDOUT, "PASS: assessment endpoint HTTP guards (method restrictions, CSRF rejection and unauthenticated list denial).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: assessment endpoint HTTP guards — ' . $error->getMessage() . "\n");
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
