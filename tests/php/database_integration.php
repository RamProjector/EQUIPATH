<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'equipath_eteeap';

try {
    $db = new mysqli($host, $user, $password, $database);
    $db->set_charset('utf8mb4');

    $tableResult = $db->query("SELECT COUNT(*) AS table_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
    $tableCount = (int) $tableResult->fetch_assoc()['table_count'];
    if ($tableCount !== 21) {
        throw new RuntimeException("Expected 21 base tables; found {$tableCount}.");
    }

    $suffix = bin2hex(random_bytes(6));
    $email = "ci-{$suffix}@example.invalid";
    $hash = password_hash('test-only-not-a-real-user-password', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (email, password_hash, given_name, family_name, account_status) VALUES (?, ?, 'CI', 'Test', 'active')");
    $stmt->bind_param('ss', $email, $hash);
    $stmt->execute();
    $userId = $db->insert_id;

    $duplicateRejected = false;
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        $duplicateRejected = $error->getCode() === 1062;
    }
    if (!$duplicateRejected) {
        throw new RuntimeException('Unique email constraint did not reject a duplicate.');
    }

    $foreignKeyRejected = false;
    try {
        $badApplicantId = 999999999;
        $badProgramId = 999999999;
        $badPeriodId = 999999999;
        $applicationNumber = "CI-{$suffix}";
        $status = 'draft';
        $stmt2 = $db->prepare("INSERT INTO applications (application_number, applicant_user_id, program_id, period_id, application_status) VALUES (?, ?, ?, ?, ?)");
        $stmt2->bind_param('siiis', $applicationNumber, $badApplicantId, $badProgramId, $badPeriodId, $status);
        $stmt2->execute();
    } catch (mysqli_sql_exception $error) {
        $foreignKeyRejected = $error->getCode() === 1452;
    }
    if (!$foreignKeyRejected) {
        throw new RuntimeException('Foreign-key constraints did not reject invalid references.');
    }

    $db->query("DELETE FROM users WHERE user_id = " . (int) $userId);
    fwrite(STDOUT, "PASS: database integration checks (table count, unique email, foreign keys).\n");
    $db->close();
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: database integration checks — ' . $error->getMessage() . "\n");
    exit(1);
}
