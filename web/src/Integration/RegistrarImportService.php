<?php
declare(strict_types=1);

/**
 * Registrar CSV reconciliation contract:
 * external_record_id,application_number,course_code,course_title,approved_credits
 *
 * Imports only create mappings to current approved local credits; they never
 * create or modify academic decisions/credits. Preview is read-only.
 */
final class RegistrarImportService
{
    public const MAX_ROWS = 1000;
    public const MAX_CSV_BYTES = 2097152;
    private const HEADERS = ['external_record_id', 'application_number', 'course_code', 'course_title', 'approved_credits'];

    /** @return list<array<string,string>> */
    public function parseCsv(string $contents): array
    {
        if ($contents === '' || strlen($contents) > self::MAX_CSV_BYTES) {
            throw new InvalidArgumentException('CSV file must be non-empty and no larger than 2 MiB.');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to prepare CSV input.');
        fwrite($stream, $contents);
        rewind($stream);
        $header = fgetcsv($stream, 4096, ',', '"', '');
        if (!is_array($header)) {
            fclose($stream);
            throw new InvalidArgumentException('CSV header is missing.');
        }
        if (isset($header[0]) && str_starts_with((string) $header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr((string) $header[0], 3);
        }
        $header = array_map(static fn($value): string => strtolower(trim((string) $value)), $header);
        if ($header !== self::HEADERS) {
            fclose($stream);
            throw new InvalidArgumentException('CSV headers must be exactly: ' . implode(',', self::HEADERS));
        }

        $rows = [];
        while (($values = fgetcsv($stream, 4096, ',', '"', '')) !== false) {
            if ($values === [null] || $values === []) continue;
            if (count($values) !== count(self::HEADERS)) {
                fclose($stream);
                throw new InvalidArgumentException('Every CSV data row must contain exactly five columns.');
            }
            $row = [];
            foreach (self::HEADERS as $index => $key) {
                $row[$key] = trim((string) $values[$index]);
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fclose($stream);
                throw new InvalidArgumentException('CSV files may contain at most 1000 data rows.');
            }
        }
        fclose($stream);
        if ($rows === []) throw new InvalidArgumentException('CSV must contain at least one data row.');
        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function previewRows(mysqli $db, string $sourceSystem, array $rows): array
    {
        $sourceSystem = $this->normalizeSourceSystem($sourceSystem);
        if (!array_is_list($rows) || $rows === [] || count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Provide between 1 and 1000 CSV rows.');
        }

        $approvedCreditQuery = $db->prepare(
            "SELECT c.accredited_credit_id, c.course_title, c.approved_credits
             FROM accredited_credits c
             INNER JOIN accreditation_decisions d ON d.accreditation_decision_id = c.accreditation_decision_id
             INNER JOIN applications a ON a.application_id = d.application_id
             WHERE a.application_number = ?
               AND UPPER(c.course_code) = UPPER(?)
               AND LOWER(TRIM(c.course_title)) = LOWER(TRIM(?))
               AND CAST(c.approved_credits AS DECIMAL(6,2)) = ?
               AND d.decision = 'approved'
               AND NOT EXISTS (
                 SELECT 1 FROM accreditation_decisions successor
                 WHERE successor.supersedes_decision_id = d.accreditation_decision_id
               )
             LIMIT 2 FOR UPDATE" 
        );
        $mappingQuery = $db->prepare(
            'SELECT local_entity_type, local_entity_id
             FROM external_record_mappings
             WHERE source_system = ? AND external_record_id = ? LIMIT 1 FOR UPDATE'
        );

        $preview = [];
        $seenExternal = [];
        $seenLocal = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $externalId = is_string($row['external_record_id'] ?? null) ? trim($row['external_record_id']) : '';
            $applicationNumber = is_string($row['application_number'] ?? null) ? trim($row['application_number']) : '';
            $courseCode = is_string($row['course_code'] ?? null) ? strtoupper(trim($row['course_code'])) : '';
            $courseTitle = is_string($row['course_title'] ?? null) ? trim($row['course_title']) : '';
            $creditsText = is_string($row['approved_credits'] ?? null) || is_numeric($row['approved_credits'] ?? null)
                ? trim((string) $row['approved_credits']) : '';
            $entry = [
                'row_number' => $rowNumber,
                'external_record_id' => $externalId,
                'application_number' => $applicationNumber,
                'course_code' => $courseCode,
                'course_title' => $courseTitle,
                'approved_credits' => $creditsText,
                'status' => 'invalid',
                'reason' => '',
            ];

            if ($externalId === '' || strlen($externalId) > 180 || preg_match('/[\x00-\x1F\x7F]/', $externalId) ||
                $applicationNumber === '' || strlen($applicationNumber) > 40 ||
                $courseCode === '' || strlen($courseCode) > 40 ||
                $courseTitle === '' || strlen($courseTitle) > 180 ||
                !preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $creditsText) ||
                (float) $creditsText < 0 || (float) $creditsText > 9999.99) {
                $entry['reason'] = 'Missing or invalid field, unsupported length, or invalid credit amount.';
                $preview[] = $entry;
                continue;
            }
            $entry['approved_credits'] = number_format((float) $creditsText, 2, '.', '');
            if (isset($seenExternal[$externalId])) {
                $entry['status'] = 'conflict';
                $entry['reason'] = 'Duplicate external record ID within this CSV.';
                $preview[] = $entry;
                continue;
            }
            $seenExternal[$externalId] = true;

            $creditAmount = $entry['approved_credits'];
            $approvedCreditQuery->bind_param('ssss', $applicationNumber, $courseCode, $courseTitle, $creditAmount);
            $approvedCreditQuery->execute();
            $localRows = $approvedCreditQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            if (count($localRows) !== 1) {
                $entry['status'] = 'conflict';
                $entry['reason'] = count($localRows) === 0
                    ? 'No current approved local credit exactly matches the application, course and amount.'
                    : 'The local credit match is ambiguous.';
                $preview[] = $entry;
                continue;
            }
            $localId = (int) $localRows[0]['accredited_credit_id'];
            if (isset($seenLocal[$localId])) {
                $entry['status'] = 'conflict';
                $entry['reason'] = 'Another row in this CSV already targets the same local approved credit.';
                $preview[] = $entry;
                continue;
            }
            $seenLocal[$localId] = true;

            $mappingQuery->bind_param('ss', $sourceSystem, $externalId);
            $mappingQuery->execute();
            $existing = $mappingQuery->get_result()->fetch_assoc();
            if (is_array($existing)) {
                if ((string) $existing['local_entity_type'] === 'accredited_credit' &&
                    (int) $existing['local_entity_id'] === $localId) {
                    $entry['status'] = 'already_mapped';
                    $entry['reason'] = 'This external record is already mapped to the matching local credit.';
                    $entry['local_entity_id'] = $localId;
                } else {
                    $entry['status'] = 'conflict';
                    $entry['reason'] = 'This external record ID is already mapped to a different local entity.';
                }
                $preview[] = $entry;
                continue;
            }

            $entry['status'] = 'ready';
            $entry['reason'] = 'Exact match to a current approved local credit.';
            $entry['local_entity_id'] = $localId;
            $preview[] = $entry;
        }
        $approvedCreditQuery->close();
        $mappingQuery->close();
        return $preview;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{batch_id:int,source_system:string,total_rows:int,accepted_rows:int,rejected_rows:int,batch_status:string,rows:list<array<string,mixed>>}
     */
    public function commit(mysqli $db, int $registrarUserId, string $sourceSystem, array $rows): array
    {
        if ($registrarUserId < 1) throw new InvalidArgumentException('A valid registrar identity is required.');
        $sourceSystem = $this->normalizeSourceSystem($sourceSystem);
        $db->begin_transaction();
        try {
            $preview = $this->previewRows($db, $sourceSystem, $rows);
            $total = count($preview);
            $accepted = 0;
            $rejected = 0;
            $errors = [];
            $startedBy = $registrarUserId;
            $batchStatus = 'processing';
            $startedAt = date('Y-m-d H:i:s');
            $zero = 0;
            $emptySummary = json_encode([], JSON_THROW_ON_ERROR);
            $batch = $db->prepare(
                'INSERT INTO integration_batches
                    (started_by, source_system, batch_status, total_rows, accepted_rows, rejected_rows, error_summary, started_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $batch->bind_param('isiiiiss', $startedBy, $sourceSystem, $batchStatus, $total, $zero, $zero, $emptySummary, $startedAt);
            $batch->execute();
            $batchId = (int) $db->insert_id;
            $batch->close();

            foreach ($preview as $entry) {
                if ($entry['status'] === 'ready') {
                    $externalId = (string) $entry['external_record_id'];
                    $entityType = 'accredited_credit';
                    $entityId = (int) $entry['local_entity_id'];
                    $insert = $db->prepare(
                        'INSERT INTO external_record_mappings
                            (integration_batch_id, source_system, external_record_id, local_entity_type, local_entity_id)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $insert->bind_param('isssi', $batchId, $sourceSystem, $externalId, $entityType, $entityId);
                    $insert->execute();
                    $insert->close();
                    $accepted++;
                } elseif ($entry['status'] === 'already_mapped') {
                    $accepted++;
                } else {
                    $rejected++;
                    $errors[] = [
                        'row_number' => (int) $entry['row_number'],
                        'external_record_id' => (string) $entry['external_record_id'],
                        'reason' => (string) $entry['reason'],
                    ];
                }
            }

            $batchStatus = $rejected === 0 ? 'completed' : 'completed_with_errors';
            $completedAt = date('Y-m-d H:i:s');
            $errorSummary = json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $update = $db->prepare(
                'UPDATE integration_batches
                 SET batch_status = ?, accepted_rows = ?, rejected_rows = ?, error_summary = ?,
                     completed_at = ?
                 WHERE integration_batch_id = ?'
            );
            $update->bind_param('sisssi', $batchStatus, $accepted, $rejected, $errorSummary, $completedAt, $batchId);
            $update->execute();
            $update->close();

            $actionKey = 'registrar.import.completed';
            $entityType = 'integration_batch';
            $auditOutcome = $accepted > 0 ? 'success' : 'failure';
            $metadata = json_encode([
                'source_system' => $sourceSystem,
                'total_rows' => $total,
                'accepted_rows' => $accepted,
                'rejected_rows' => $rejected,
                'batch_status' => $batchStatus,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $registrarUserId, $actionKey, $entityType, $batchId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $db->commit();
            return [
                'batch_id' => $batchId,
                'source_system' => $sourceSystem,
                'total_rows' => $total,
                'accepted_rows' => $accepted,
                'rejected_rows' => $rejected,
                'batch_status' => $batchStatus,
                'rows' => $preview,
            ];
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }

    private function normalizeSourceSystem(string $sourceSystem): string
    {
        $sourceSystem = strtoupper(trim($sourceSystem));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,99}$/D', $sourceSystem)) {
            throw new InvalidArgumentException('Source system must be a 2–100 character identifier.');
        }
        return $sourceSystem;
    }
}
