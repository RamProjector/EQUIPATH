<?php
declare(strict_types=1);

/** Records coordinator review of a pending document version transactionally. */
final class ApplicationDocumentReviewService
{
    /** @return array{document_id:int,application_id:int,previous_review_status:string,review_status:string,application_status:string} */
    public function review(mysqli $db, int $reviewerUserId, int $documentId, string $outcome, string $notes): array
    {
        if ($reviewerUserId < 1 || $documentId < 1) {
            throw new InvalidArgumentException('Positive reviewer and document identifiers are required.');
        }
        if (!in_array($outcome, ['accepted', 'rejected', 'replacement_requested'], true)) {
            throw new InvalidArgumentException('Unsupported document review outcome.');
        }
        $notes = trim($notes);
        if (strlen($notes) > 900 || str_contains($notes, "\0")) {
            throw new InvalidArgumentException('Document review feedback must be at most 900 bytes and contain no NUL bytes.');
        }
        if ($outcome !== 'accepted' && $notes === '') {
            throw new InvalidArgumentException('A reason is required when rejecting a document or requesting a replacement.');
        }

        $db->begin_transaction();
        try {
            $find = $db->prepare(
                'SELECT a.application_id, a.applicant_user_id, a.program_id, a.application_status,
                        d.document_type, d.version_number, d.review_status,
                        (SELECT MAX(latest.version_number) FROM application_documents latest
                         WHERE latest.application_id = d.application_id AND latest.document_type = d.document_type) AS latest_version_number
                 FROM application_documents d
                 INNER JOIN applications a ON a.application_id = d.application_id
                 WHERE d.document_id = ?
                 FOR UPDATE'
            );
            $find->bind_param('i', $documentId);
            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            $find->close();

            if (!is_array($row)) {
                throw new DomainException('The document is not available for review.');
            }
            $applicationId = (int) $row['application_id'];
            $applicantUserId = (int) $row['applicant_user_id'];
            $applicationStatus = (string) $row['application_status'];
            $previousReviewStatus = (string) $row['review_status'];

            if (!in_array($applicationStatus, ['submitted', 'screening', 'needs_correction'], true)) {
                throw new DomainException('The application is not in a reviewable state.');
            }
            if ($previousReviewStatus !== 'pending') {
                throw new DomainException('Only a pending document version can be reviewed. Upload a new version for further review.');
            }
            if ((int) $row['version_number'] !== (int) $row['latest_version_number']) {
                throw new DomainException('Only the latest document version can be reviewed.');
            }

            $requirementQuery = $db->prepare(
                'SELECT program_requirement_id FROM program_requirements
                 WHERE program_id = ? AND requirement_key = ? AND is_active = TRUE LIMIT 1'
            );
            $programId = (int) $row['program_id'];
            $documentType = (string) $row['document_type'];
            $requirementQuery->bind_param('is', $programId, $documentType);
            $requirementQuery->execute();
            $requirementExists = $requirementQuery->get_result()->fetch_assoc() !== null;
            $requirementQuery->close();
            if (!$requirementExists) {
                throw new DomainException('The document no longer matches an active program requirement.');
            }

            $updateDocument = $db->prepare(
                'UPDATE application_documents SET review_status = ? WHERE document_id = ? AND review_status = ?'
            );
            $updateDocument->bind_param('sis', $outcome, $documentId, $previousReviewStatus);
            $updateDocument->execute();
            if ($updateDocument->affected_rows !== 1) {
                $updateDocument->close();
                throw new DomainException('The document review state changed; refresh before trying again.');
            }
            $updateDocument->close();

            $nextApplicationStatus = $applicationStatus;
            if (in_array($outcome, ['rejected', 'replacement_requested'], true) &&
                in_array($applicationStatus, ['submitted', 'screening'], true)) {
                $nextApplicationStatus = 'needs_correction';
                $updateApplication = $db->prepare(
                    'UPDATE applications SET application_status = ? WHERE application_id = ?'
                );
                $updateApplication->bind_param('si', $nextApplicationStatus, $applicationId);
                $updateApplication->execute();
                $updateApplication->close();

                $fromStatus = $applicationStatus;
                $reason = 'Document ' . $outcome . ': ' . (string) $row['document_type'] . ' version ' . (int) $row['version_number'];
                if ($notes !== '') $reason .= ' — ' . $notes;
                $history = $db->prepare(
                    'INSERT INTO application_status_history
                        (application_id, changed_by, from_status, to_status, reason)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $history->bind_param('iisss', $applicationId, $reviewerUserId, $fromStatus, $nextApplicationStatus, $reason);
                $history->execute();
                $history->close();
            }

            $actionKey = 'application_document.reviewed';
            $entityType = 'application_document';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'application_id' => $applicationId,
                'document_type' => (string) $row['document_type'],
                'version_number' => (int) $row['version_number'],
                'previous_review_status' => $previousReviewStatus,
                'review_status' => $outcome,
                'application_status' => $nextApplicationStatus,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $reviewerUserId, $actionKey, $entityType, $documentId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $notificationType = 'application_document_review';
            $notificationTitle = match ($outcome) {
                'accepted' => 'Document accepted',
                'rejected' => 'Document rejected',
                default => 'Document replacement requested',
            };
            $notificationMessage = match ($outcome) {
                'accepted' => 'Your ' . (string) $row['document_type'] . ' document version ' . (int) $row['version_number'] . ' was accepted.',
                'rejected' => 'Your ' . (string) $row['document_type'] . ' document version ' . (int) $row['version_number'] . ' was rejected. Reason: ' . $notes,
                default => 'Please replace your ' . (string) $row['document_type'] . ' document version ' . (int) $row['version_number'] . '. Reason: ' . $notes,
            };
            $notification = $db->prepare(
                'INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $notification->bind_param('isssi', $applicantUserId, $notificationType, $notificationTitle, $notificationMessage, $applicationId);
            $notification->execute();
            $notification->close();

            $db->commit();
            return [
                'document_id' => $documentId,
                'application_id' => $applicationId,
                'previous_review_status' => $previousReviewStatus,
                'review_status' => $outcome,
                'application_status' => $nextApplicationStatus,
            ];
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}
