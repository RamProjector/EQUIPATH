<?php
declare(strict_types=1);

/**
 * Creates an applicant-owned draft only for an active program and an open
 * application period. Callers must authenticate, authorize, and validate CSRF.
 */
final class ApplicationDraftService
{
    public function create(mysqli $db, int $userId, int $programId, int $periodId): array
    {
        if ($userId < 1 || $programId < 1 || $periodId < 1) {
            throw new InvalidArgumentException('Positive identifiers are required.');
        }

        $eligible = $db->prepare(
            "SELECT p.program_id
             FROM academic_programs p
             INNER JOIN application_periods ap ON ap.period_id = ?
             WHERE p.program_id = ?
               AND p.is_active = TRUE
               AND ap.period_status = 'open'
               AND CURRENT_TIMESTAMP BETWEEN ap.opens_at AND ap.closes_at
             LIMIT 1"
        );
        $eligible->bind_param('ii', $periodId, $programId);
        $eligible->execute();
        $isEligible = $eligible->get_result()->fetch_assoc() !== null;
        $eligible->close();

        if (!$isEligible) {
            throw new DomainException('The selected program or application period is unavailable.');
        }

        $applicationNumber = 'EQ-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
        $db->begin_transaction();

        try {
            $insert = $db->prepare(
                "INSERT INTO applications
                    (application_number, applicant_user_id, program_id, period_id, application_status)
                 VALUES (?, ?, ?, ?, 'draft')"
            );
            $insert->bind_param('siii', $applicationNumber, $userId, $programId, $periodId);
            $insert->execute();
            $applicationId = (int) $db->insert_id;
            $insert->close();

            $history = $db->prepare(
                "INSERT INTO application_status_history
                    (application_id, changed_by, from_status, to_status, reason)
                 VALUES (?, ?, NULL, 'draft', 'Application draft created')"
            );
            $history->bind_param('ii', $applicationId, $userId);
            $history->execute();
            $history->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'application_id' => $applicationId,
            'application_number' => $applicationNumber,
            'application_status' => 'draft',
            'program_id' => $programId,
            'period_id' => $periodId,
        ];
    }
}
