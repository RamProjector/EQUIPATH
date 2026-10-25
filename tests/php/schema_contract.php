<?php
declare(strict_types=1);

/**
 * Static safety contracts for the initial schema. Database behavior is tested
 * separately in tests/php/database_integration.php against a real MySQL service.
 */
$schemaPath = dirname(__DIR__, 2) . '/database/migrations/001_initial_schema.sql';
if (!is_file($schemaPath)) {
    fwrite(STDERR, "FAIL: initial database migration is missing.\n");
    exit(1);
}
$sql = (string) file_get_contents($schemaPath);
$requiredTables = [
    'roles', 'users', 'user_roles', 'academic_programs', 'application_periods',
    'applications', 'application_status_history', 'application_documents',
    'screening_reviews', 'evaluator_assignments', 'evaluation_rubrics',
    'rubric_criteria', 'evaluation_scores', 'assessment_events', 'assessment_results',
    'credit_recommendations', 'accreditation_decisions', 'notifications', 'audit_logs',
    'integration_batches', 'external_record_mappings',
];
$missing = [];
foreach ($requiredTables as $table) {
    if (!preg_match('/CREATE\\s+TABLE\\s+' . preg_quote($table, '/') . '\\s*\\(/i', $sql)) {
        $missing[] = $table;
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'FAIL: missing required tables: ' . implode(', ', $missing) . "\n");
    exit(1);
}
if (preg_match('/\\bDROP\\s+(?:DATABASE|TABLE)\\b/i', $sql)) {
    fwrite(STDERR, "FAIL: initial migration must not drop databases or tables.\n");
    exit(1);
}
if (preg_match('/CREATE\\s+USER|GRANT\\s+ALL|INSERT\\s+INTO\\s+users/i', $sql)) {
    fwrite(STDERR, "FAIL: schema must not create privileged accounts or embed environment-specific grants.\n");
    exit(1);
}
if (substr_count(strtoupper($sql), 'ENGINE=INNODB') < count($requiredTables)) {
    fwrite(STDERR, "FAIL: every table must explicitly use InnoDB for foreign-key and transaction support.\n");
    exit(1);
}
foreach (['FOREIGN KEY', 'CHECK (', 'password_hash', 'storage_key', 'sha256', 'audit_logs'] as $contract) {
    if (stripos($sql, $contract) === false) {
        fwrite(STDERR, "FAIL: schema is missing required safety contract: {$contract}.\n");
        exit(1);
    }
}
fwrite(STDOUT, 'PASS: schema contract covers ' . count($requiredTables) . " tables and safety checks.\n");
