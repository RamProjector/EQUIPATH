<?php
declare(strict_types=1);

/**
 * Records an authorized screening outcome and its related workflow side effects.
 *
 * The caller must authenticate the reviewer, validate CSRF, and enforce the
 * coordinator role. The transaction keeps the review, application status,
 * status history, audit event and applicant notification consistent.
 */
final class ApplicationScreeningService
{
    private const OUTCOME_STATUS = [
        'eligible' => 'eligible',
        'ineligible' => 'ineligible',
        'needs_correction' => 'needs_correction',
    ];

    /**
     * @return array{application_id:int,previous_status:string,application_status:string,outcome:string}
     */
    public function review(
        mysqli $db,
        int $reviewerUserId,
        int $applicationId,
        string $outcome,
        string $notes
    ): array {
        if ($reviewerUserId < 1 || $applicationId < 1) {
            throw new InvalidArgumentException('Positive reviewer and application identifiers are required.');
        }
        if (!isset(self::OUTCOME_STATUS[$outcome])) {
            throw new InvalidArgumentException('A supported screening outcome is required.');
        }
        if (strlen($notes) > 1000 || str_contains($notes, "\0")) {
            throw new InvalidArgumentException('Screening feedback must be at most 1000 bytes and contain no NUL bytes.');
        }

        $targetStatus = self::OUTCOME_STATUS[$outcome];
        $db->begin_transaction();

        try {
            $find = $db->prepare(
                'SELECT applicant_user_id, application_status
                 FROM applications
                 WHERE application_id = ?
                 FOR UPDATE'
            );
            $find->bind_param('i', $applicationId);
            $find->execute();
            $application = $find->get_result()->fetch_assoc();
            $find->close();

            if (!is_array($application)) {
                throw new DomainException('The application is unavailable for screening.');
            }

            $previousStatus = (string) $application['application_status'];
            if (!in_array($previousStatus, ['submitted', 'screening', 'needs_correction'], true)) {
                throw new DomainException('The application is not in a screenable status.');
            }

            $review = $db->prepare(
                'INSERT INTO screening_reviews
                    (application_id, reviewer_user_id, outcome, notes, reviewed_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $review->bind_param('iiss', $applicationId, $reviewerUserId, $outcome, $notes);
            $review->execute();
            $review->close();

            $update = $db->prepare(
                'UPDATE applications SET application_status = ? WHERE application_id = ?'
            );
            $update->bind_param('si', $targetStatus, $applicationId);
            $update->execute();
            if ($update->affected_rows !== 1) {
                $update->close();
                throw new RuntimeException('Application status was not updated.');
            }
            $update->close();

            $reason = 'Screening outcome: ' . $outcome;
            if ($notes !== '') {
                $reason .= ' — ' . $notes;
            }
            $history = $db->prepare(
                'INSERT INTO application_status_history
                    (application_id, changed_by, from_status, to_status, reason)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $history->bind_param('iisss', $applicationId, $reviewerUserId, $previousStatus, $targetStatus, $reason);
            $history->execute();
            $history->close();

            $metadata = json_encode([
                'previous_status' => $previousStatus,
                'outcome' => $outcome,
                'application_status' => $targetStatus,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $actionKey = 'application.screened';
            $entityType = 'application';
            $auditOutcome = 'success';
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $reviewerUserId, $actionKey, $entityType, $applicationId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $applicantUserId = (int) $application['applicant_user_id'];
            $notificationType = 'application_screening';
            $title = 'Application screening update';
            $statusLabel = match ($outcome) {
                'eligible' => 'eligible to proceed',
                'ineligible' => 'marked ineligible',
                'needs_correction' => 'returned for corrections',
            };
            $message = 'Your application has been ' . $statusLabel . '.';
            if ($notes !== '') {
                $message .= ' Reviewer feedback: ' . $notes;
            }
            $notification = $db->prepare(
                'INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $notification->bind_param('isssi', $applicantUserId, $notificationType, $title, $message, $applicationId);
            $notification->execute();
            $notification->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'application_id' => $applicationId,
            'previous_status' => $previousStatus,
            'application_status' => $targetStatus,
            'outcome' => $outcome,
        ];
    }
}
