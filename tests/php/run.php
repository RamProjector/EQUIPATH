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

test('public workspace exposes accessible sign-in and notification controls', function (): void {
    $root = dirname(__DIR__, 2);
    $page = (string) file_get_contents($root . '/web/public/index.php');
    $script = (string) file_get_contents($root . '/web/assets/js/app.js');

    foreach (['id="login-form"', 'id="email"', 'id="password"', 'id="login-status"', 'id="workspace"', 'id="notification-list"', 'id="refresh-notifications"', 'id="logout-button"', 'id="evaluation-workspace"', 'id="evaluation-assignment-list"', 'id="evaluation-editor"', 'id="evaluation-form"', 'id="evaluation-criteria"', 'id="save-evaluation"', 'id="submit-evaluation"', 'id="assessment-workspace"', 'id="assessment-form"', 'id="assessment-list"', 'id="assessment-application"', 'id="assessment-result-form"', 'id="credit-recommendations-editor"', 'id="credit-recommendations-form"', 'id="credit-recommendation-fields"', 'id="accreditation-workspace"', 'id="accreditation-application"', 'id="accreditation-decision-form"', 'id="approved-credit-options"', 'aria-live="polite"'] as $expected) {
        assertTrue(str_contains($page, $expected), "Workspace markup is missing required accessible control: {$expected}");
    }
    foreach (['../api/csrf.php', '../api/authenticate.php', '../api/logout.php', '../api/notifications.php', '../api/mark-notification-read.php', '../api/evaluation-assignments.php', '../api/evaluation-rubric.php', '../api/save-evaluation.php'] as $endpoint) {
        assertTrue(str_contains($script, $endpoint), "Workspace script is missing API integration: {$endpoint}");
    }
    $assessmentScript = (string) file_get_contents($root . '/web/assets/js/assessments.js');
    foreach (['../api/assessments.php', '../api/assessment-eligible-applications.php', '../api/create-assessment.php', '../api/record-assessment-result.php'] as $endpoint) {
        assertTrue(str_contains($assessmentScript, $endpoint), "Assessment client is missing API integration: {$endpoint}");
    }
    assertTrue(!str_contains($assessmentScript, 'innerHTML'), 'Assessment data must be rendered as text, not interpreted as markup.');
    $creditScript = (string) file_get_contents($root . '/web/assets/js/credit-recommendations.js');
    foreach (['../api/credit-recommendations.php', '../api/submit-credit-recommendations.php'] as $endpoint) {
        assertTrue(str_contains($creditScript, $endpoint), "Credit recommendation client is missing API integration: {$endpoint}");
    }
    assertTrue(str_contains($page, 'assets/js/credit-recommendations.js'), 'Landing page must load the credit recommendation client module.');
    assertTrue(!str_contains($creditScript, 'innerHTML'), 'Credit recommendation data must be rendered as text, not interpreted as markup.');
    $accreditationScript = (string) file_get_contents($root . '/web/assets/js/accreditation.js');
    foreach (['../api/accreditation-candidates.php', '../api/accreditation-decisions.php', '../api/application-credit-recommendations.php', '../api/record-accreditation-decision.php'] as $endpoint) {
        assertTrue(str_contains($accreditationScript, $endpoint), "Accreditation client is missing API integration: {$endpoint}");
    }
    assertTrue(str_contains($page, 'assets/js/accreditation.js'), 'Landing page must load the accreditation client module.');
    $applicantScript = (string) file_get_contents($root . '/web/assets/js/applicant-applications.js');
    foreach (['../api/application-options.php', '../api/applications.php', '../api/create-application.php', '../api/submit-application.php', '../api/application-documents.php', '../api/upload-document.php', '../api/download-document.php'] as $endpoint) {
        assertTrue(str_contains($applicantScript, $endpoint), "Applicant workspace is missing API integration: {$endpoint}");
    }
    foreach (['id="applicant-workspace"', 'id="new-application-form"', 'id="applicant-upload-form"', 'id="applicant-document-list"'] as $expected) {
        assertTrue(str_contains($page, $expected), "Landing page is missing applicant workflow markup: {$expected}");
    }
    assertTrue(str_contains($page, 'assets/js/applicant-applications.js'), 'Landing page must load the applicant workflow module.');
    assertTrue(!str_contains($applicantScript, 'innerHTML'), 'Applicant and document data must be rendered as text, not interpreted as markup.');
    $screeningScript = (string) file_get_contents($root . '/web/assets/js/screening.js');
    foreach (['../api/screening-candidates.php', '../api/application-documents.php', '../api/download-document.php', '../api/review-application-document.php', '../api/screen-application.php'] as $endpoint) {
        assertTrue(str_contains($screeningScript, $endpoint), "Screening workspace is missing API integration: {$endpoint}");
    }
    foreach (['id="screening-workspace"', 'id="screening-candidate-list"', 'id="screening-document-list"', 'id="screening-decision-form"'] as $expected) {
        assertTrue(str_contains($page, $expected), "Landing page is missing screening workflow markup: {$expected}");
    }
    assertTrue(str_contains($page, 'assets/js/screening.js'), 'Landing page must load the screening client module.');
    assertTrue(!str_contains($screeningScript, 'innerHTML'), 'Screening data must be rendered as text, not interpreted as markup.');
    $assignmentScript = (string) file_get_contents($root . '/web/assets/js/evaluator-assignments.js');
    foreach (['../api/evaluator-assignment-options.php', '../api/assign-evaluator.php'] as $endpoint) {
        assertTrue(str_contains($assignmentScript, $endpoint), "Assignment workspace is missing API integration: {$endpoint}");
    }
    assertTrue(str_contains($page, 'id="assignment-workspace"'), 'Landing page is missing coordinator assignment workspace markup.');
    assertTrue(str_contains($page, 'assets/js/evaluator-assignments.js'), 'Landing page must load the evaluator assignment module.');
    assertTrue(!str_contains($assignmentScript, 'innerHTML'), 'Assignment data must be rendered as text, not interpreted as markup.');
    assertTrue(!str_contains($accreditationScript, 'innerHTML'), 'Accreditation data must be rendered as text, not interpreted as markup.');
    assertTrue(str_contains($page, 'assets/js/assessments.js'), 'Landing page must load the assessment client module.');
    assertTrue(str_contains($script, 'message.textContent'), 'Notification message must be rendered as text, not interpreted as markup.');
    assertTrue(!str_contains($script, 'innerHTML'), 'Workspace script must not use innerHTML for untrusted server-provided notification content.');
});

$total = count($tests);
$passed = count(array_filter($tests, static fn (array $test): bool => $test['passed']));
fwrite(STDOUT, "\nPHP smoke tests: {$passed}/{$total} passed.\n");

if ($failures !== []) {
    exit(1);
}
