<?php
declare(strict_types=1);

/** HTTP method and authentication guards for applicant application endpoints. */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) {
    throw new RuntimeException('Unable to resolve web root.');
}
$port = random_int(20000, 45000);
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-applicant-http-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDir, 0700, true)) {
    throw new RuntimeException('Unable to create isolated session directory.');
}
$server = null;
$exitCode = 0;

/** @return array{0:int,1:string} */
function requestApplicantEndpoint(string $url, string $method, ?string $body = null, array $headers = []): array
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
    $optionsUrl = $base . 'application-options.php';
    $applicationsUrl = $base . 'applications.php';
    $createUrl = $base . 'create-application.php';
    $submitUrl = $base . 'submit-application.php';
    $documentsUrl = $base . 'application-documents.php?application_id=1';
    $historyUrl = $base . 'application-history.php';
    $uploadUrl = $base . 'upload-document.php';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        [$status] = requestApplicantEndpoint($createUrl, 'GET');
        if ($status === 405) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('HTTP server did not become ready for applicant endpoint tests.');
    }

    foreach ([$createUrl => 'application creation', $submitUrl => 'application submission', $uploadUrl => 'document upload', $historyUrl => 'application history'] as $url => $label) {
        [$status, $body] = requestApplicantEndpoint($url, 'GET');
        $json = json_decode($body, true);
        if ($status !== 405 || ($json['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject GET with HTTP 405.');
        }
    }
    foreach ([$optionsUrl => 'application options', $applicationsUrl => 'application list'] as $url => $label) {
        [$status, $body] = requestApplicantEndpoint($url, 'POST');
        $json = json_decode($body, true);
        if ($status !== 405 || ($json['error'] ?? null) !== 'method_not_allowed') {
            throw new RuntimeException(ucfirst($label) . ' endpoint did not reject POST with HTTP 405.');
        }
    }

    [$optionsStatus, $optionsBody] = requestApplicantEndpoint($optionsUrl, 'GET');
    $optionsJson = json_decode($optionsBody, true);
    if ($optionsStatus !== 401 || ($optionsJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Application options were not protected by authentication.');
    }
    [$applicationsStatus, $applicationsBody] = requestApplicantEndpoint($applicationsUrl, 'GET');
    $applicationsJson = json_decode($applicationsBody, true);
    if ($applicationsStatus !== 401 || ($applicationsJson['error'] ?? null) !== 'authentication_required') {
        throw new RuntimeException('Application list was not protected by authentication.');
    }
    [$historyMethodStatus, $historyMethodBody] = requestApplicantEndpoint($historyUrl, 'POST');
    if ($historyMethodStatus !== 405 || (json_decode($historyMethodBody, true)['error'] ?? null) !== 'method_not_allowed') {
        throw new RuntimeException('Application history endpoint did not reject POST with HTTP 405.');
    }
    [$historyValidationStatus, $historyValidationBody] = requestApplicantEndpoint($historyUrl, 'GET');
    if ($historyValidationStatus !== 422 || (json_decode($historyValidationBody, true)['error'] ?? null) !== 'invalid_application_details') {
        throw new RuntimeException('Application history endpoint did not validate application ID before accessing data.');
    }

    [$documentsStatus, $documentsBody] = requestApplicantEndpoint($base . 'application-documents.php', 'GET');
    $documentsJson = json_decode($documentsBody, true);
    if ($documentsStatus !== 422 || ($documentsJson['error'] ?? null) !== 'invalid_application_details') {
        throw new RuntimeException('Document list did not validate application ID before accessing data.');
    }

    $createBody = json_encode(['program_id' => 1, 'period_id' => 1], JSON_THROW_ON_ERROR);
    [$csrfStatus, $csrfBody] = requestApplicantEndpoint($createUrl, 'POST', $createBody, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($createBody),
    ]);
    $csrfJson = json_decode($csrfBody, true);
    if ($csrfStatus !== 403 || ($csrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Application creation without CSRF was not denied before authentication/database access.');
    }

    $submitBody = json_encode(['application_id' => 1], JSON_THROW_ON_ERROR);
    [$submitCsrfStatus, $submitCsrfBody] = requestApplicantEndpoint($submitUrl, 'POST', $submitBody, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($submitBody),
    ]);
    $submitCsrfJson = json_decode($submitCsrfBody, true);
    if ($submitCsrfStatus !== 403 || ($submitCsrfJson['error'] ?? null) !== 'csrf_validation_failed') {
        throw new RuntimeException('Application submission without CSRF was not denied before authentication/database access.');
    }

    fwrite(STDOUT, "PASS: applicant application endpoint HTTP guards (methods, authentication and identifier validation).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: applicant application endpoint HTTP guards — ' . $error->getMessage() . "\n");
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
