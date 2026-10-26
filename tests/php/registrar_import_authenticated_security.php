<?php
declare(strict_types=1);

/**
 * Authenticated registrar endpoint checks using isolated sessions and CI MySQL.
 * Confirms a preview token is scoped to its originating PHP session and that
 * non-registrar accounts are denied before token validation/import processing.
 */
$webRoot = realpath(dirname(__DIR__, 2) . '/web');
if ($webRoot === false) throw new RuntimeException('Unable to resolve web root.');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$createdRoleIds = [];
$userIds = [];
$batchIds = [];
$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'equipath-registrar-auth-' . bin2hex(random_bytes(8));
$server = null;
$exitCode = 0;
$port = random_int(20000, 45000);

function registrarAuthAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function registrarAuthSeedSession(string $directory, string $sessionId, array $values): void
{
    ini_set('session.save_path', $directory);
    session_name('EQUIPATH_SESSION');
    session_id($sessionId);
    if (!session_start()) throw new RuntimeException('Unable to seed test session.');
    $_SESSION = $values;
    session_write_close();
}

function registrarAuthRequest(string $url, string $sessionId, string $csrf, string $token): array
{
    $body = json_encode(['preview_token' => $token], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Connection: close',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            'Cookie: EQUIPATH_SESSION=' . $sessionId,
            'X-CSRF-Token: ' . $csrf,
        ]),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 5,
    ]]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) $status = (int) $match[1];
    }
    return [$status, is_string($response) ? $response : ''];
}

try {
    if (!mkdir($sessionDir, 0700, true)) throw new RuntimeException('Unable to create isolated session directory.');

    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );

    $roleKeys = ['registrar' => 'Registrar', 'applicant' => 'Applicant'];
    $roleIds = [];
    foreach ($roleKeys as $key => $label) {
        $find = $db->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
        $find->bind_param('s', $key);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        if (is_array($row)) {
            $roleIds[$key] = (int) $row['role_id'];
        } else {
            $insert = $db->prepare('INSERT INTO roles (role_key, display_name) VALUES (?, ?)');
            $insert->bind_param('ss', $key, $label);
            $insert->execute();
            $roleIds[$key] = (int) $db->insert_id;
            $createdRoleIds[] = $roleIds[$key];
            $insert->close();
        }
    }

    $suffix = bin2hex(random_bytes(8));
    $passwordHash = password_hash('unused-test-password', PASSWORD_DEFAULT);
    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    foreach (['registrar', 'applicant'] as $kind) {
        $email = $kind . '-endpoint-' . $suffix . '@example.invalid';
        $given = ucfirst($kind);
        $family = 'RegistrarEndpointTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $given, $family);
        $insertUser->execute();
        $userIds[$kind] = (int) $db->insert_id;
        $roleId = $roleIds[$kind];
        $addRole = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $addRole->bind_param('ii', $userIds[$kind], $roleId);
        $addRole->execute();
        $addRole->close();
    }
    $insertUser->close();

    $token = bin2hex(random_bytes(32));
    $retryToken = bin2hex(random_bytes(32));
    $csrfA = bin2hex(random_bytes(32));
    $csrfB = bin2hex(random_bytes(32));
    $csrfRetry = bin2hex(random_bytes(32));
    $csrfUnauthorized = bin2hex(random_bytes(32));
    $sessionA = bin2hex(random_bytes(16));
    $sessionB = bin2hex(random_bytes(16));
    $sessionRetry = bin2hex(random_bytes(16));
    $sessionUnauthorized = bin2hex(random_bytes(16));
    $pending = [
        'token_hash' => hash('sha256', $token),
        'registrar_user_id' => $userIds['registrar'],
        'source_system' => 'TEST_COLLEGE',
        'rows' => [[
            'external_record_id' => 'EXT-' . $suffix,
            'application_number' => 'NO-APPLICATION-' . $suffix,
            'course_code' => 'TEST101',
            'course_title' => 'Nonexistent test course',
            'approved_credits' => '3.00',
        ]],
        'created_at' => time(),
    ];
    $pendingRetry = array_merge($pending, [
        'token_hash' => hash('sha256', $retryToken),
        'rows' => [],
    ]);

    registrarAuthSeedSession($sessionDir, $sessionA, [
        'user_id' => $userIds['registrar'],
        '_csrf_token' => $csrfA,
        'registrar_import_preview' => $pending,
    ]);
    registrarAuthSeedSession($sessionDir, $sessionB, [
        'user_id' => $userIds['registrar'],
        '_csrf_token' => $csrfB,
    ]);
    registrarAuthSeedSession($sessionDir, $sessionRetry, [
        'user_id' => $userIds['registrar'],
        '_csrf_token' => $csrfRetry,
        'registrar_import_preview' => $pendingRetry,
    ]);
    registrarAuthSeedSession($sessionDir, $sessionUnauthorized, [
        'user_id' => $userIds['applicant'],
        '_csrf_token' => $csrfUnauthorized,
    ]);

    $server = proc_open([
        PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-d', 'display_errors=0',
        '-S', '127.0.0.1:' . $port, '-t', $webRoot,
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (!is_resource($server)) throw new RuntimeException('Unable to start isolated PHP HTTP server.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $url = 'http://127.0.0.1:' . $port . '/api/registrar-import-commit.php';
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 2]]);
        @file_get_contents($url, false, $context);
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+405/', $line)) $ready = true;
        }
        if ($ready) break;
        usleep(100000);
    }
    registrarAuthAssert($ready, 'Registrar commit endpoint did not become ready.');

    // An intentionally invalid stored row set forces a controlled 422 after
    // token validation. The token must remain available so a corrected/retried
    // request is not treated as a consumed preview.
    [$failedCommitStatus, $failedCommitBody] = registrarAuthRequest($url, $sessionRetry, $csrfRetry, $retryToken);
    $failedCommitJson = json_decode($failedCommitBody, true);
    registrarAuthAssert(
        $failedCommitStatus === 422 && ($failedCommitJson['error'] ?? null) === 'invalid_import_rows',
        'The deliberately invalid preview did not fail with the expected validation response.'
    );
    [$retryStatus, $retryBody] = registrarAuthRequest($url, $sessionRetry, $csrfRetry, $retryToken);
    $retryJson = json_decode($retryBody, true);
    registrarAuthAssert(
        $retryStatus === 422 && ($retryJson['error'] ?? null) === 'invalid_import_rows',
        'A failed commit consumed the preview token and prevented retry.'
    );

    [$crossSessionStatus, $crossSessionBody] = registrarAuthRequest($url, $sessionB, $csrfB, $token);
    $crossSessionJson = json_decode($crossSessionBody, true);
    registrarAuthAssert(
        $crossSessionStatus === 409 && ($crossSessionJson['error'] ?? null) === 'preview_missing_expired_or_invalid',
        'A registrar session without the preview state accepted a token from another session.'
    );

    // A well-formed row with no matching local credit creates a rejected
    // import batch without a mapping, but still completes the endpoint flow.
    // The endpoint must consume that preview on success and reject its replay.
    [$successStatus, $successBody] = registrarAuthRequest($url, $sessionA, $csrfA, $token);
    $successJson = json_decode($successBody, true);
    if (is_array($successJson) && isset($successJson['batch']['batch_id'])) {
        $batchIds[] = (int) $successJson['batch']['batch_id'];
    }
    registrarAuthAssert(
        $successStatus === 201 && ($successJson['ok'] ?? false) === true &&
        (int) ($successJson['batch']['rejected_rows'] ?? 0) === 1,
        'The valid registrar request did not complete with the expected rejected-row result.'
    );

    [$replayStatus, $replayBody] = registrarAuthRequest($url, $sessionA, $csrfA, $token);
    $replayJson = json_decode($replayBody, true);
    registrarAuthAssert(
        $replayStatus === 409 && ($replayJson['error'] ?? null) === 'preview_missing_expired_or_invalid',
        'A preview token was accepted again after a successful commit.'
    );

    [$unauthorizedStatus, $unauthorizedBody] = registrarAuthRequest($url, $sessionUnauthorized, $csrfUnauthorized, $token);
    $unauthorizedJson = json_decode($unauthorizedBody, true);
    registrarAuthAssert(
        $unauthorizedStatus === 403 && ($unauthorizedJson['error'] ?? null) === 'access_denied',
        'A non-registrar account was not denied before import processing.'
    );

    fwrite(STDOUT, "PASS: authenticated registrar endpoint checks (failed-commit retry, cross-session rejection, non-registrar denial, and replay rejection after success).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: authenticated registrar endpoint checks — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_file($path)) @unlink($path);
    }
    @rmdir($sessionDir);

    if ($db instanceof mysqli) {
        foreach ($batchIds as $batchId) {
            $delete = $db->prepare(
                "DELETE FROM audit_logs
                 WHERE actor_user_id = ? AND entity_type = 'integration_batch' AND entity_id = ?"
            );
            $actorUserId = $userIds['registrar'] ?? 0;
            $delete->bind_param('ii', $actorUserId, $batchId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM external_record_mappings WHERE integration_batch_id = ?');
            $delete->bind_param('i', $batchId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM integration_batches WHERE integration_batch_id = ?');
            $delete->bind_param('i', $batchId);
            $delete->execute();
            $delete->close();
        }

        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }
        foreach ($createdRoleIds as $roleId) {
            $delete = $db->prepare('DELETE FROM roles WHERE role_id = ?');
            $delete->bind_param('i', $roleId);
            $delete->execute();
            $delete->close();
        }
        $db->close();
    }
}

exit($exitCode);
