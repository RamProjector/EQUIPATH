<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Integration/RegistrarImportService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = null;
$userId = $programId = $periodId = $applicationId = $decisionId = $creditId = null;
$batchIds = [];
$triggerName = 'trg_equipath_registrar_test_fail_audit';
$triggerCreated = false;
$exitCode = 0;

function registrarIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function registrarIntegrationInsert(mysqli $db, string $sql, string $types, array $values): int
{
    $statement = $db->prepare($sql);
    $statement->bind_param($types, ...$values);
    $statement->execute();
    $id = (int) $db->insert_id;
    $statement->close();
    return $id;
}

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    $suffix = strtoupper(bin2hex(random_bytes(6)));

    $email = 'registrar-import-' . strtolower($suffix) . '@example.invalid';
    $passwordHash = password_hash('registrar-import-test-password', PASSWORD_DEFAULT);
    $givenName = 'Registrar';
    $familyName = 'Integration Test';
    $userId = registrarIntegrationInsert(
        $db,
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')",
        'ssss',
        [$email, $passwordHash, $givenName, $familyName]
    );

    $programId = registrarIntegrationInsert(
        $db,
        'INSERT INTO academic_programs (program_code, program_name) VALUES (?, ?)',
        'ss',
        ['REG-' . $suffix, 'Registrar integration test program']
    );
    $periodId = registrarIntegrationInsert(
        $db,
        "INSERT INTO application_periods (period_name, opens_at, closes_at, period_status)
         VALUES (?, ?, ?, 'open')",
        'sss',
        ['Registrar test period ' . $suffix, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400)]
    );
    $applicationNumber = 'REG-' . $suffix;
    $applicationId = registrarIntegrationInsert(
        $db,
        "INSERT INTO applications
            (application_number, applicant_user_id, program_id, period_id, application_status)
         VALUES (?, ?, ?, ?, 'draft')",
        'siii',
        [$applicationNumber, $userId, $programId, $periodId]
    );
    $rationale = 'Created only as an isolated registrar import integration-test fixture.';
    $decisionId = registrarIntegrationInsert(
        $db,
        "INSERT INTO accreditation_decisions (application_id, decided_by, decision, rationale)
         VALUES (?, ?, 'approved', ?)",
        'iis',
        [$applicationId, $userId, $rationale]
    );
    $courseCode = 'IT-' . substr($suffix, 0, 6);
    $courseTitle = 'Registrar Integration Test Course';
    $credits = '3.00';
    $creditId = registrarIntegrationInsert(
        $db,
        'INSERT INTO accredited_credits (accreditation_decision_id, course_code, course_title, approved_credits)
         VALUES (?, ?, ?, ?)',
        'isss',
        [$decisionId, $courseCode, $courseTitle, $credits]
    );

    $service = new RegistrarImportService();
    $sourceSystem = 'TEST_' . substr($suffix, 0, 8);
    $externalId = 'EXT-' . $suffix;
    $rows = [[
        'external_record_id' => $externalId,
        'application_number' => $applicationNumber,
        'course_code' => $courseCode,
        'course_title' => $courseTitle,
        'approved_credits' => $credits,
    ]];

    $preview = $service->previewRows($db, $sourceSystem, $rows);
    registrarIntegrationAssert(count($preview) === 1 && $preview[0]['status'] === 'ready', 'A matching current approved credit should be ready to map.');
    registrarIntegrationAssert((int) $preview[0]['local_entity_id'] === $creditId, 'Preview should resolve the exact approved credit.');

    $result = $service->commit($db, $userId, $sourceSystem, $rows);
    $batchIds[] = $result['batch_id'];
    registrarIntegrationAssert($result['accepted_rows'] === 1 && $result['rejected_rows'] === 0, 'A valid row should be accepted.');
    registrarIntegrationAssert($result['batch_status'] === 'completed', 'A fully accepted batch should be completed.');

    $mapping = $db->prepare(
        'SELECT local_entity_type, local_entity_id, integration_batch_id
         FROM external_record_mappings WHERE source_system = ? AND external_record_id = ?'
    );
    $mapping->bind_param('ss', $sourceSystem, $externalId);
    $mapping->execute();
    $mapped = $mapping->get_result()->fetch_assoc();
    $mapping->close();
    registrarIntegrationAssert(is_array($mapped), 'Successful import must persist an external record mapping.');
    registrarIntegrationAssert($mapped['local_entity_type'] === 'accredited_credit' && (int) $mapped['local_entity_id'] === $creditId, 'Mapping must target the exact approved credit.');
    registrarIntegrationAssert((int) $mapped['integration_batch_id'] === $result['batch_id'], 'Mapping must reference the committed batch.');

    $audit = $db->prepare("SELECT COUNT(*) AS count FROM audit_logs WHERE actor_user_id = ? AND action_key = 'registrar.import.completed' AND entity_id = ?");
    $audit->bind_param('ii', $userId, $result['batch_id']);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['count'];
    $audit->close();
    registrarIntegrationAssert($auditCount === 1, 'Successful import must write one audit record.');

    // Repeating the same import must not create a second external mapping.
    $repeat = $service->commit($db, $userId, $sourceSystem, $rows);
    $batchIds[] = $repeat['batch_id'];
    registrarIntegrationAssert($repeat['accepted_rows'] === 1 && $repeat['rejected_rows'] === 0, 'A repeated matching row should be accepted as already mapped.');
    $count = $db->prepare('SELECT COUNT(*) AS count FROM external_record_mappings WHERE source_system = ? AND external_record_id = ?');
    $count->bind_param('ss', $sourceSystem, $externalId);
    $count->execute();
    $mappingCount = (int) $count->get_result()->fetch_assoc()['count'];
    $count->close();
    registrarIntegrationAssert($mappingCount === 1, 'Repeating an import must not duplicate the external mapping.');

    // Force the audit write to fail and prove the import transaction rolls back its batch and mapping.
    $db->query(
        "CREATE TRIGGER `{$triggerName}` BEFORE INSERT ON audit_logs
         FOR EACH ROW
         BEGIN
           IF NEW.action_key = 'registrar.import.completed' THEN
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'intentional registrar integration test failure';
           END IF;
         END"
    );
    $triggerCreated = true;
    $rollbackSource = $sourceSystem . '_ROLLBACK';
    $rollbackExternal = $externalId . '-ROLLBACK';
    $rollbackRows = [[
        'external_record_id' => $rollbackExternal,
        'application_number' => $applicationNumber,
        'course_code' => $courseCode,
        'course_title' => $courseTitle,
        'approved_credits' => $credits,
    ]];
    try {
        $service->commit($db, $userId, $rollbackSource, $rollbackRows);
        throw new RuntimeException('An intentional audit failure did not abort the import transaction.');
    } catch (mysqli_sql_exception $error) {
        // The service must roll back the inserted batch and mapping when audit fails.
    }
    $db->query("DROP TRIGGER IF EXISTS `{$triggerName}`");
    $triggerCreated = false;

    $check = $db->prepare('SELECT COUNT(*) AS count FROM external_record_mappings WHERE source_system = ? AND external_record_id = ?');
    $check->bind_param('ss', $rollbackSource, $rollbackExternal);
    $check->execute();
    $rollbackMappings = (int) $check->get_result()->fetch_assoc()['count'];
    $check->close();
    registrarIntegrationAssert($rollbackMappings === 0, 'Failed import must not leave a mapping behind.');

    $check = $db->prepare('SELECT COUNT(*) AS count FROM integration_batches WHERE source_system = ?');
    $check->bind_param('s', $rollbackSource);
    $check->execute();
    $rollbackBatches = (int) $check->get_result()->fetch_assoc()['count'];
    $check->close();
    registrarIntegrationAssert($rollbackBatches === 0, 'Failed import must roll back its integration batch.');

    fwrite(STDOUT, "PASS: registrar import MySQL integration (exact approved-credit mapping, audit, repeat safety, transaction rollback).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: registrar import MySQL integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        if ($triggerCreated) {
            try { $db->query("DROP TRIGGER IF EXISTS `{$triggerName}`"); } catch (Throwable) {}
        }
        foreach ($batchIds as $batchId) {
            try {
                $delete = $db->prepare('DELETE FROM external_record_mappings WHERE integration_batch_id = ?');
                $delete->bind_param('i', $batchId);
                $delete->execute();
                $delete->close();
                $delete = $db->prepare('DELETE FROM integration_batches WHERE integration_batch_id = ?');
                $delete->bind_param('i', $batchId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($userId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
                $delete->bind_param('i', $userId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($creditId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM accredited_credits WHERE accredited_credit_id = ?');
                $delete->bind_param('i', $creditId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($decisionId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM accreditation_decisions WHERE accreditation_decision_id = ?');
                $delete->bind_param('i', $decisionId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($applicationId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM applications WHERE application_id = ?');
                $delete->bind_param('i', $applicationId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($periodId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM application_periods WHERE period_id = ?');
                $delete->bind_param('i', $periodId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($programId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM academic_programs WHERE program_id = ?');
                $delete->bind_param('i', $programId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        if ($userId !== null) {
            try {
                $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
                $delete->bind_param('i', $userId);
                $delete->execute();
                $delete->close();
            } catch (Throwable) {}
        }
        $db->close();
    }
}
exit($exitCode);
