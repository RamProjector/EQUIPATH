<?php
declare(strict_types=1);

/** HTTP method and CSRF guard checks for the evaluator assignment endpoint. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-assignment-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestAssignmentEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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
    $url = $base . 'assign-evaluator.php';
    $optionsUrl = $base . 'evaluator-assignment-options.php';
    $rubricOptionsUrl = $base . 'rubric-management.php';
    $saveRubricUrl = $base . 'save-rubric.php';
    $reportsUrl = $base . 'reports.php';
    $exportReportUrl = $base . 'export-report.php';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestAssignmentEndpoint($url, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for evaluator assignment tests.');
    }

    [$methodStatus, $methodBody] = requestAssignmentEndpoint($url, 'GET');
    $methodJson = json_decode($methodBody, true);
    if ($methodStatus !== 405 || ($methodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Assignment endpoint did not reject GET with HTTP 405.');
    }

    $body = '{"application_id":1,"evaluator_user_id":1}';
    [$optionsMethodStatus, $optionsMethodBody] = requestAssignmentEndpoint($optionsUrl, 'POST');
    if ($optionsMethodStatus !== 405 || (json_decode($optionsMethodBody, true)['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Evaluator assignment options endpoint did not reject POST with HTTP 405.');
    }
    [$optionsAuthStatus, $optionsAuthBody] = requestAssignmentEndpoint($optionsUrl, 'GET');
    if ($optionsAuthStatus !== 401 || (json_decode($optionsAuthBody, true)['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Evaluator assignment options endpoint was not protected by authentication.');
    }

    [$rubricOptionsMethodStatus, $rubricOptionsMethodBody] = requestAssignmentEndpoint($rubricOptionsUrl, 'POST');
    if ($rubricOptionsMethodStatus !== 405 || (json_decode($rubricOptionsMethodBody, true)['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Rubric management endpoint did not reject POST with HTTP 405.');
    }
    [$rubricOptionsAuthStatus, $rubricOptionsAuthBody] = requestAssignmentEndpoint($rubricOptionsUrl, 'GET');
    if ($rubricOptionsAuthStatus !== 401 || (json_decode($rubricOptionsAuthBody, true)['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Rubric management endpoint was not protected by authentication.');
    }
    [$saveRubricMethodStatus, $saveRubricMethodBody] = requestAssignmentEndpoint($saveRubricUrl, 'GET');
    if ($saveRubricMethodStatus !== 405 || (json_decode($saveRubricMethodBody, true)['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Rubric save endpoint did not reject GET with HTTP 405.');
    }
    [$saveRubricCsrfStatus, $saveRubricCsrfBody] = requestAssignmentEndpoint($saveRubricUrl, 'POST', '{"program_id":1,"rubric_name":"test","criteria":[]}', [
        'Content-Type: application/json',
        'Content-Length: ' . strlen('{"program_id":1,"rubric_name":"test","criteria":[]}'),
    ]);
    if ($saveRubricCsrfStatus !== 403 || (json_decode($saveRubricCsrfBody, true)['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Rubric save POST without CSRF was not denied before authentication/database access.');
    }

    foreach ([$reportsUrl => 'aggregate reports', $exportReportUrl => 'report export'] as $reportUrl => $label) {
        [$reportMethodStatus, $reportMethodBody] = requestAssignmentEndpoint($reportUrl, 'POST');
        if ($reportMethodStatus !== 405 || (json_decode($reportMethodBody, true)['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject POST with HTTP 405.');
        }
        [$reportAuthStatus, $reportAuthBody] = requestAssignmentEndpoint($reportUrl, 'GET');
        if ($reportAuthStatus !== 401 || (json_decode($reportAuthBody, true)['error'] ?? null) !== 'authentication_required') {
            throw new RuntimeException(ucfirst($label) . ' endpoint was not protected by authentication.');
        }
    }

    [$csrfStatus, $csrfBody] = requestAssignmentEndpoint($url, 'POST', $body, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Assignment POST without a valid CSRF token was not rejected before authentication/database access.');
    }

    fwrite(STDOUT, "PASS: evaluator assignment endpoint HTTP guards (GET rejected; POST without CSRF token denied).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: evaluator assignment endpoint HTTP guards — ' . $error->getMessage() . "\n");
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
