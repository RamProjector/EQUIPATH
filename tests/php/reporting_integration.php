<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Reporting/ReportingService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$userId = null;
$programId = null;
$periodId = null;
$applicationId = null;

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    $suffix = bin2hex(random_bytes(8));

    $email = 'reporting-' . $suffix . '@example.invalid';
    $passwordHash = password_hash('report-test-password', PASSWORD_DEFAULT);
    $givenName = 'Report';
    $familyName = 'Test';
    $user = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $user->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
    $user->execute();
    $userId = (int) $db->insert_id;
    $user->close();

    $programCode = 'REPORT-' . strtoupper(substr($suffix, 0, 8));
    $programName = '=HYPERLINK("https://example.invalid","Spreadsheet formula test")';
    $program = $db->prepare('INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)');
    $program->bind_param('ss', $programCode, $programName);
    $program->execute();
    $programId = (int) $db->insert_id;
    $program->close();

    $periodName = 'Reporting period ' . $suffix;
    $opensAt = date('Y-m-d H:i:s', time() - 3600);
    $closesAt = date('Y-m-d H:i:s', time() + 86400);
    $period = $db->prepare(
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')"
    );
    $period->bind_param('sss', $periodName, $opensAt, $closesAt);
    $period->execute();
    $periodId = (int) $db->insert_id;
    $period->close();

    $applicationNumber = 'REPORT-' . strtoupper(substr($suffix, 0, 8));
    $applicationStatus = 'draft';
    $application = $db->prepare(
        'INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $application->bind_param('siiis', $applicationNumber, $userId, $programId, $periodId, $applicationStatus);
    $application->execute();
    $applicationId = (int) $db->insert_id;
    $application->close();

    $service = new ReportingService();
    $today = new DateTimeImmutable('today');
    $range = $service->normalizeDateRange($today->modify('-30 days')->format('Y-m-d'), $today->format('Y-m-d'));
    try {
        $service->normalizeDateRange($today->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'));
        throw new RuntimeException('An inverted report date range was accepted.');
    } catch (InvalidArgumentException) {
        // Date ranges must be ordered.
    }
    try {
        $service->normalizeDateRange('2026-02-30', $today->format('Y-m-d'));
        throw new RuntimeException('An invalid calendar date was accepted.');
    } catch (InvalidArgumentException) {
        // Date values must be real ISO calendar dates.
    }
    try {
        $service->normalizeDateRange($today->modify('-366 days')->format('Y-m-d'), $today->format('Y-m-d'));
        throw new RuntimeException('A report range longer than 366 days was accepted.');
    } catch (InvalidArgumentException) {
        // Report ranges are capped at 366 calendar days.
    }

    $report = $service->build($db, $range['from'], $range['to']);
    if (!isset($report['totals']['applications_created']) ||
        !isset($report['applications_by_status']) ||
        !isset($report['applications_by_program']) ||
        (int) $report['totals']['applications_created'] < 1) {
        throw new RuntimeException('Aggregate report omitted expected application totals or groups.');
    }
    $statusLabels = array_column($report['applications_by_status'], 'label');
    if (!in_array('draft', $statusLabels, true)) {
        throw new RuntimeException('Report aggregation did not preserve textual status labels.');
    }
    $programRows = array_values(array_filter(
        $report['applications_by_program'],
        static fn(array $row): bool => ($row['code'] ?? '') === $programCode
    ));
    if (count($programRows) !== 1 || $programRows[0]['name'] !== $programName) {
        throw new RuntimeException('Report program aggregation did not preserve program identity.');
    }
    $csv = $service->toCsv($report);
    if (!str_contains($csv, "'=HYPERLINK") || !str_contains($csv, 'EquiPath aggregate report')) {
        throw new RuntimeException('CSV export did not label the report or neutralize formula-like program names.');
    }
    if (str_contains($csv, $email) || str_contains($csv, $givenName . ' ' . $familyName)) {
        throw new RuntimeException('Aggregate report export unexpectedly contains user identity fields.');
    }

    fwrite(STDOUT, "PASS: reporting service (date range validation, aggregate labels, formula-safe CSV and no applicant PII).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: reporting integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        if ($applicationId !== null) {
            $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
            $delete->bind_param('i', $applicationId);
            $delete->execute();
            $delete->close();
        }
        if ($userId !== null) {
            $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }
        if ($periodId !== null) {
            $delete = $db->prepare('DELETE FROM application_periods WHERE period_id = ?');
            $delete->bind_param('i', $periodId);
            $delete->execute();
            $delete->close();
        }
        if ($programId !== null) {
            $delete = $db->prepare('DELETE FROM academic_programs WHERE program_id = ?');
            $delete->bind_param('i', $programId);
            $delete->execute();
            $delete->close();
        }
        $db->close();
    }
}
exit($exitCode);
