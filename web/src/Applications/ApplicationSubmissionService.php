<?php
declare(strict_types=1);

final class ApplicationSubmissionIncompleteException extends DomainException
{
    /** @param list<string> $missingRequirements */
    public function __construct(public readonly array $missingRequirements)
    {
        parent::__construct('Required document evidence is missing or has been rejected.');
    }
}

/**
 * Finalizes an applicant draft only when required document evidence is present.
 * Callers must authenticate, validate CSRF and enforce the applicant role.
 */
final class ApplicationSubmissionService
{
    /** @return array{application_id:int,application_number:string,previous_status:string,application_status:string,submitted_at:string} */
    public function submit(mysqli $db, int $userId, int $applicationId): array
    {
        if ($userId < 1 || $applicationId < 1) {
            throw new InvalidArgumentException('Positive applicant and application identifiers are required.');
        }

        $db->begin_transaction();
        try {
            $find = $db->prepare(
                'SELECT application_number, applicant_user_id, program_id, application_status
                 FROM applications WHERE application_id = ? FOR UPDATE'
            );
            $find->bind_param('i', $applicationId);
            $find->execute();
            $application = $find->get_result()->fetch_assoc();
            $find->close();

            if (!is_array($application) || (int) $application['applicant_user_id'] !== $userId) {
                throw new AuthorizationDeniedException('Application submission is not permitted.');
            }

            $previousStatus = (string) $application['application_status'];
            if (!in_array($previousStatus, ['draft', 'needs_correction'], true)) {
                throw new DomainException('Only a draft or application returned for correction can be submitted.');
            }

            $coordinatorCheck = $db->query(
                "SELECT COUNT(*) AS total
                 FROM user_roles ur
                 INNER JOIN roles r ON r.role_id = ur.role_id
                 INNER JOIN users u ON u.user_id = ur.user_id
                 WHERE r.role_key = 'coordinator' AND u.account_status = 'active'"
            );
            $coordinatorCount = (int) ($coordinatorCheck->fetch_assoc()['total'] ?? 0);
            if ($coordinatorCount < 1) {
                throw new DomainException('No active coordinator is configured to receive application submissions.');
            }

            $programId = (int) $application['program_id'];
            $requiredCountQuery = $db->prepare(
                'SELECT COUNT(*) AS total
                 FROM program_requirements
                 WHERE program_id = ? AND is_active = TRUE AND is_required = TRUE'
            );
            $requiredCountQuery->bind_param('i', $programId);
            $requiredCountQuery->execute();
            $requiredCount = (int) ($requiredCountQuery->get_result()->fetch_assoc()['total'] ?? 0);
            $requiredCountQuery->close();
            if ($requiredCount < 1) {
                throw new DomainException('The program has no active required-document configuration.');
            }

            $missingQuery = $db->prepare(
                "SELECT pr.requirement_key, pr.requirement_name
                 FROM program_requirements pr
                 WHERE pr.program_id = ? AND pr.is_active = TRUE AND pr.is_required = TRUE
                   AND NOT EXISTS (
                     SELECT 1
                     FROM application_documents d
                     WHERE d.application_id = ?
                       AND d.document_type = pr.requirement_key
                       AND d.version_number = (
                         SELECT MAX(latest.version_number)
                         FROM application_documents latest
                         WHERE latest.application_id = d.application_id
                           AND latest.document_type = d.document_type
                       )
                       AND d.review_status IN ('pending', 'accepted')
                   )
                 ORDER BY pr.requirement_name, pr.requirement_key"
            );
            $missingQuery->bind_param('ii', $programId, $applicationId);
            $missingQuery->execute();
            $missingResult = $missingQuery->get_result();
            $missingRequirements = [];
            while ($row = $missingResult->fetch_assoc()) {
                $missingRequirements[] = [
                    'key' => (string) $row['requirement_key'],
                    'name' => (string) $row['requirement_name'],
                ];
            }
            $missingQuery->close();
            if ($missingRequirements !== []) {
                throw new ApplicationSubmissionIncompleteException($missingRequirements);
            }

            $update = $db->prepare(
                "UPDATE applications
                 SET application_status = 'submitted', submitted_at = CURRENT_TIMESTAMP
                 WHERE application_id = ?"
            );
            $update->bind_param('i', $applicationId);
            $update->execute();
            $update->close();

            $reason = $previousStatus === 'needs_correction'
                ? 'Applicant resubmitted after corrections'
                : 'Applicant submitted application for screening';
            $history = $db->prepare(
                "INSERT INTO application_status_history
                    (application_id, changed_by, from_status, to_status, reason)
                 VALUES (?, ?, ?, 'submitted', ?)"
            );
            $history->bind_param('iiss', $applicationId, $userId, $previousStatus, $reason);
            $history->execute();
            $history->close();

            $actionKey = $previousStatus === 'needs_correction'
                ? 'application.resubmitted'
                : 'application.submitted';
            $entityType = 'application';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'application_number' => (string) $application['application_number'],
                'previous_status' => $previousStatus,
                'application_status' => 'submitted',
                'required_document_count' => $requiredCount,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $userId, $actionKey, $entityType, $applicationId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $notificationType = 'application_submitted';
            $notificationTitle = 'Application submitted for screening';
            $notificationMessage = 'Application ' . (string) $application['application_number'] . ' is ready for initial screening.';
            $notify = $db->prepare(
                "INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 SELECT u.user_id, ?, ?, ?, ?
                 FROM user_roles ur
                 INNER JOIN roles r ON r.role_id = ur.role_id
                 INNER JOIN users u ON u.user_id = ur.user_id
                 WHERE r.role_key = 'coordinator' AND u.account_status = 'active'"
            );
            $notify->bind_param('sssi', $notificationType, $notificationTitle, $notificationMessage, $applicationId);
            $notify->execute();
            $notify->close();

            $submittedAtQuery = $db->prepare('SELECT submitted_at FROM applications WHERE application_id = ?');
            $submittedAtQuery->bind_param('i', $applicationId);
            $submittedAtQuery->execute();
            $submittedAt = (string) $submittedAtQuery->get_result()->fetch_assoc()['submitted_at'];
            $submittedAtQuery->close();

            $db->commit();
            return [
                'application_id' => $applicationId,
                'application_number' => (string) $application['application_number'],
                'previous_status' => $previousStatus,
                'application_status' => 'submitted',
                'submitted_at' => $submittedAt,
            ];
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}
