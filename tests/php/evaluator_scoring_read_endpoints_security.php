<?php
declare(strict_types=1);

/** HTTP method and unauthenticated-access guards for evaluator read endpoints. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-evaluation-read-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestEvaluationReadEndpoint(string $url, string $method): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Connection: close\r\n",
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

    $queueUrl = 'http://127.0.0.1:' . $port . '/api/evaluation-assignments.php';
    $rubricUrl = 'http://127.0.0.1:' . $port . '/api/evaluation-rubric.php?evaluator_assignment_id=1';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestEvaluationReadEndpoint($queueUrl, 'POST');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for evaluator read endpoint tests.');
    }

    [$queueMethodStatus, $queueMethodBody] = requestEvaluationReadEndpoint($queueUrl, 'POST');
    $queueMethodJson = json_decode($queueMethodBody, true);
    if ($queueMethodStatus !== 405 || ($queueMethodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Evaluator queue did not reject POST with HTTP 405.');
    }

    [$queueAuthStatus, $queueAuthBody] = requestEvaluationReadEndpoint($queueUrl, 'GET');
    $queueAuthJson = json_decode($queueAuthBody, true);
    if ($queueAuthStatus !== 401 || ($queueAuthJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Evaluator queue did not reject unauthenticated GET with HTTP 401.');
    }

    [$rubricMethodStatus, $rubricMethodBody] = requestEvaluationReadEndpoint($rubricUrl, 'POST');
    $rubricMethodJson = json_decode($rubricMethodBody, true);
    if ($rubricMethodStatus !== 405 || ($rubricMethodJson['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Evaluation rubric endpoint did not reject POST with HTTP 405.');
    }

    [$rubricAuthStatus, $rubricAuthBody] = requestEvaluationReadEndpoint($rubricUrl, 'GET');
    $rubricAuthJson = json_decode($rubricAuthBody, true);
    if ($rubricAuthStatus !== 401 || ($rubricAuthJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Evaluation rubric endpoint did not reject unauthenticated GET with HTTP 401.');
    }

    fwrite(STDOUT, "PASS: evaluator queue and rubric HTTP guards (method restrictions and unauthenticated access denied).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: evaluator queue/rubric HTTP guards — ' . $error->getMessage() . "\n");
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
