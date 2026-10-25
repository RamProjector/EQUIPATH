<?php
declare(strict_types=1);

/**
 * Lightweight dependency-free smoke tests for the PHP API.
 * Run from the repository root: php tests/php/run.php
 *
 * These are bootstrap contract tests, not a replacement for feature,
 * authorization, integration, or end-to-end tests.
 */

$tests = [];
$failures = [];

function test(string $name, callable $assertion): void
{
    global $tests, $failures;
    try {
        $assertion();
        $tests[] = ['name' => $name, 'passed' => true];
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $tests[] = ['name' => $name, 'passed' => false];
        $failures[] = ['name' => $name, 'error' => $error->getMessage()];
        fwrite(STDERR, "FAIL: {$name} — {$error->getMessage()}\n");
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function loadHealthResponse(): array
{
    $endpoint = dirname(__DIR__, 2) . '/web/api/health.php';
    if (!is_file($endpoint)) {
        throw new RuntimeException('Health endpoint does not exist: web/api/health.php');
    }

    ob_start();
    try {
        require $endpoint;
        $output = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Health endpoint must emit a JSON object.');
    }

    return $decoded;
}

test('health endpoint returns a successful JSON contract', function (): void {
    $response = loadHealthResponse();
    assertTrue(($response['ok'] ?? null) === true, 'Expected ok=true.');
    assertTrue(($response['service'] ?? null) === 'equipath-api', 'Unexpected service identifier.');
    assertTrue(isset($response['status']) && is_string($response['status']), 'Expected a string status.');
    assertTrue(isset($response['database']) && is_string($response['database']), 'Expected a database status string.');
});

test('health endpoint does not claim the database was checked when it was not', function (): void {
    $response = loadHealthResponse();
    assertTrue(($response['database'] ?? null) === 'not_checked', 'Database must be reported as not_checked until a real connectivity check exists.');
});

test('health endpoint does not expose secrets or personal data', function (): void {
    $response = loadHealthResponse();
    $forbiddenKeys = ['password', 'secret', 'token', 'connection_string', 'database_host', 'email', 'applicant'];
    foreach ($forbiddenKeys as $key) {
        assertTrue(!array_key_exists($key, array_change_key_case($response, CASE_LOWER)), "Health response must not expose '{$key}'.");
    }
});

test('public landing page includes core project identity and workflow labels', function (): void {
    $page = dirname(__DIR__, 2) . '/web/public/index.php';
    assertTrue(is_file($page), 'Landing page does not exist: web/public/index.php');

    $source = (string) file_get_contents($page);
    foreach (['EquiPath', 'ETEEAP', 'Apply', 'Screen', 'Evaluate', 'Decide'] as $expected) {
        assertTrue(str_contains($source, $expected), "Landing page is missing expected text: {$expected}");
    }
});

$total = count($tests);
$passed = count(array_filter($tests, static fn (array $test): bool => $test['passed']));
fwrite(STDOUT, "\nPHP smoke tests: {$passed}/{$total} passed.\n");

if ($failures !== []) {
    exit(1);
}
