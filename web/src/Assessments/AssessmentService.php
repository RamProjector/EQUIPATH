<?php
declare(strict_types=1);

/**
 * Coordinates assessment scheduling and first-time result recording.
 *
 * HTTP callers must authenticate and enforce the coordinator role before
 * invoking these mutating operations.
 */
final class AssessmentService
{
    /**
     * Schedule an assessment for an eligible application.
     *
     * @return array{assessment_event_id:int,application_id:int,event_status:string,application_status:string}
     */
    public function schedule(
        mysqli $db,
        int $coordinatorUserId,
        int $applicationId,
        string $assessmentType,
        string $startsAt,
        string $endsAt,
        ?string $locationOrLink,
        ?string $notes
    ): array {
        if ($coordinatorUserId < 1 || $applicationId < 1) {
            throw new InvalidArgumentException('Positive coordinator and application identifiers are required.');
        }
        if (!in_array($assessmentType, ['interview', 'examination', 'demonstration', 'other'], true)) {
            throw new InvalidArgumentException('Unsupported assessment type.');
        }
        $start = $this->parseDateTime($startsAt);
        $end = $this->parseDateTime($endsAt);
        if ($end <= $start) {
            throw new InvalidArgumentException('Assessment end time must be after its start time.');
        }
        if (($locationOrLink !== null && strlen($locationOrLink) > 500) ||
            ($notes !== null && strlen($notes) > 4000)) {
            throw new InvalidArgumentException('Assessment location or notes exceed the permitted length.');
        }

        $db->begin_transaction();
        try {
            $clock = $db->query('SELECT CURRENT_TIMESTAMP AS database_now')->fetch_assoc();
            $databaseNow = (string) ($clock['database_now'] ?? '');
            if ($databaseNow !== '' && $startsAt <= $databaseNow) {
                throw new InvalidArgumentException('Assessment start time must be in the future.');
            }

            $applicationQuery = $db->prepare(
                'SELECT applicant_user_id, application_number, application_status
                 FROM applications WHERE application_id = ? FOR UPDATE'
            );
            $applicationQuery->bind_param('i', $applicationId);
            $applicationQuery->execute();
            $application = $applicationQuery->get_result()->fetch_assoc();
            $applicationQuery->close();
            if (!is_array($application)) {
                throw new DomainException('The application is not available for assessment scheduling.');
            }

            $applicationStatus = (string) $application['application_status'];
            if ($applicationStatus === 'under_evaluation') {
                $assignmentsQuery = $db->prepare(
                    "SELECT COUNT(*) AS total,
                            SUM(CASE WHEN assignment_status = 'submitted' THEN 1 ELSE 0 END) AS submitted
                     FROM evaluator_assignments
                     WHERE application_id = ?
                       AND assignment_status IN ('assigned', 'in_progress', 'submitted')"
                );
                $assignmentsQuery->bind_param('i', $applicationId);
                $assignmentsQuery->execute();
                $assignmentCounts = $assignmentsQuery->get_result()->fetch_assoc();
                $assignmentsQuery->close();
                $totalAssignments = (int) ($assignmentCounts['total'] ?? 0);
                $submittedAssignments = (int) ($assignmentCounts['submitted'] ?? 0);
                if ($totalAssignments < 1 || $submittedAssignments !== $totalAssignments) {
                    throw new DomainException('All assigned evaluations must be submitted before scheduling an assessment.');
                }
            } elseif ($applicationStatus !== 'assessment_scheduled') {
                throw new DomainException('Assessment scheduling requires submitted evaluator assessments or an existing assessment schedule.');
            }

            $eventStatus = 'scheduled';
            $insert = $db->prepare(
                'INSERT INTO assessment_events
                    (application_id, created_by, assessment_type, starts_at, ends_at,
                     location_or_link, event_status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->bind_param(
                'iissssss',
                $applicationId,
                $coordinatorUserId,
                $assessmentType,
                $startsAt,
                $endsAt,
                $locationOrLink,
                $eventStatus,
                $notes
            );
            $insert->execute();
            $eventId = (int) $db->insert_id;
            $insert->close();

            if ($applicationStatus !== 'assessment_scheduled') {
                $nextStatus = 'assessment_scheduled';
                $update = $db->prepare(
                    'UPDATE applications SET application_status = ? WHERE application_id = ?'
                );
                $update->bind_param('si', $nextStatus, $applicationId);
                $update->execute();
                $update->close();

                $reason = 'Assessment scheduled';
                $history = $db->prepare(
                    'INSERT INTO application_status_history
                        (application_id, changed_by, from_status, to_status, reason)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $history->bind_param('iisss', $applicationId, $coordinatorUserId, $applicationStatus, $nextStatus, $reason);
                $history->execute();
                $history->close();
                $applicationStatus = $nextStatus;
            }

            $actionKey = 'assessment.scheduled';
            $entityType = 'assessment_event';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'application_id' => $applicationId,
                'assessment_type' => $assessmentType,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $coordinatorUserId, $actionKey, $entityType, $eventId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $notificationType = 'assessment_scheduled';
            $notificationTitle = 'Assessment scheduled';
            $notificationMessage = 'Your ' . $assessmentType . ' assessment for application ' .
                (string) $application['application_number'] . ' is scheduled for ' . $startsAt . '.';
            $notification = $db->prepare(
                'INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $applicantUserId = (int) $application['applicant_user_id'];
            $notification->bind_param(
                'isssi',
                $applicantUserId,
                $notificationType,
                $notificationTitle,
                $notificationMessage,
                $applicationId
            );
            $notification->execute();
            $notification->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'assessment_event_id' => $eventId,
            'application_id' => $applicationId,
            'event_status' => 'scheduled',
            'application_status' => $applicationStatus,
        ];
    }

    /**
     * Record a result once, complete the event and notify the applicant.
     *
     * @return array{assessment_event_id:int,application_id:int,outcome:string,application_status:string}
     */
    public function recordResult(
        mysqli $db,
        int $coordinatorUserId,
        int $eventId,
        string $outcome,
        ?string $score,
        ?string $feedback
    ): array {
        if ($coordinatorUserId < 1 || $eventId < 1) {
            throw new InvalidArgumentException('Positive coordinator and assessment event identifiers are required.');
        }
        if (!in_array($outcome, ['passed', 'failed', 'incomplete', 'excused'], true)) {
            throw new InvalidArgumentException('Unsupported assessment outcome.');
        }
        if ($score !== null && (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $score) || (float) $score > 99999.99)) {
            throw new InvalidArgumentException('Assessment score must be a non-negative number with at most two decimal places.');
        }
        if ($feedback !== null && strlen($feedback) > 4000) {
            throw new InvalidArgumentException('Assessment feedback cannot exceed 4000 bytes.');
        }

        $db->begin_transaction();
        try {
            $eventQuery = $db->prepare(
                'SELECT e.application_id, e.event_status, e.ends_at,
                        a.applicant_user_id, a.application_number, a.application_status
                 FROM assessment_events e
                 INNER JOIN applications a ON a.application_id = e.application_id
                 WHERE e.assessment_event_id = ?
                 FOR UPDATE'
            );
            $eventQuery->bind_param('i', $eventId);
            $eventQuery->execute();
            $event = $eventQuery->get_result()->fetch_assoc();
            $eventQuery->close();
            if (!is_array($event)) {
                throw new DomainException('The assessment event is not available.');
            }
            if ((string) $event['event_status'] !== 'scheduled') {
                throw new DomainException('Only a scheduled assessment can receive its first result.');
            }
            $timeQuery = $db->prepare(
                'SELECT ends_at <= CURRENT_TIMESTAMP AS assessment_ended
                 FROM assessment_events WHERE assessment_event_id = ?'
            );
            $timeQuery->bind_param('i', $eventId);
            $timeQuery->execute();
            $ended = (int) ($timeQuery->get_result()->fetch_assoc()['assessment_ended'] ?? 0) === 1;
            $timeQuery->close();
            if (!$ended) {
                throw new DomainException('Assessment results cannot be recorded before the scheduled end time.');
            }

            $existingQuery = $db->prepare(
                'SELECT 1 FROM assessment_results WHERE assessment_event_id = ? LIMIT 1'
            );
            $existingQuery->bind_param('i', $eventId);
            $existingQuery->execute();
            $hasResult = $existingQuery->get_result()->num_rows > 0;
            $existingQuery->close();
            if ($hasResult) {
                throw new DomainException('An assessment result has already been recorded.');
            }

            $insert = $db->prepare(
                'INSERT INTO assessment_results (assessment_event_id, recorded_by, outcome, score, feedback)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->bind_param('iisss', $eventId, $coordinatorUserId, $outcome, $score, $feedback);
            $insert->execute();
            $insert->close();

            $completedStatus = 'completed';
            $completeEvent = $db->prepare(
                'UPDATE assessment_events SET event_status = ? WHERE assessment_event_id = ? AND event_status = \'scheduled\''
            );
            $completeEvent->bind_param('si', $completedStatus, $eventId);
            $completeEvent->execute();
            if ($completeEvent->affected_rows !== 1) {
                $completeEvent->close();
                throw new RuntimeException('The assessment event could not be completed.');
            }
            $completeEvent->close();

            $applicationId = (int) $event['application_id'];
            $applicationStatus = (string) $event['application_status'];
            $remainingQuery = $db->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN e.event_status = 'completed' AND ar.assessment_result_id IS NOT NULL THEN 1 ELSE 0 END) AS completed_with_results
                 FROM assessment_events e
                 LEFT JOIN assessment_results ar ON ar.assessment_event_id = e.assessment_event_id
                 WHERE e.application_id = ? AND e.event_status <> 'cancelled'"
            );
            $remainingQuery->bind_param('i', $applicationId);
            $remainingQuery->execute();
            $eventCounts = $remainingQuery->get_result()->fetch_assoc();
            $remainingQuery->close();
            $totalEvents = (int) ($eventCounts['total'] ?? 0);
            $completedEvents = (int) ($eventCounts['completed_with_results'] ?? 0);
            if ($totalEvents > 0 && $completedEvents === $totalEvents && $applicationStatus === 'assessment_scheduled') {
                $nextStatus = 'awaiting_decision';
                $update = $db->prepare(
                    'UPDATE applications SET application_status = ? WHERE application_id = ?'
                );
                $update->bind_param('si', $nextStatus, $applicationId);
                $update->execute();
                $update->close();

                $reason = 'All scheduled assessments have recorded results';
                $history = $db->prepare(
                    'INSERT INTO application_status_history
                        (application_id, changed_by, from_status, to_status, reason)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $history->bind_param('iisss', $applicationId, $coordinatorUserId, $applicationStatus, $nextStatus, $reason);
                $history->execute();
                $history->close();
                $applicationStatus = $nextStatus;
            }

            $actionKey = 'assessment.result_recorded';
            $entityType = 'assessment_event';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'application_id' => $applicationId,
                'outcome' => $outcome,
                'score_recorded' => $score !== null,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $coordinatorUserId, $actionKey, $entityType, $eventId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $notificationType = 'assessment_result';
            $notificationTitle = 'Assessment result available';
            $notificationMessage = 'A result has been recorded for your assessment in application ' .
                (string) $event['application_number'] . '. Sign in to view the result.';
            $notification = $db->prepare(
                'INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $applicantUserId = (int) $event['applicant_user_id'];
            $notification->bind_param(
                'isssi',
                $applicantUserId,
                $notificationType,
                $notificationTitle,
                $notificationMessage,
                $applicationId
            );
            $notification->execute();
            $notification->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'assessment_event_id' => $eventId,
            'application_id' => $applicationId,
            'outcome' => $outcome,
            'application_status' => $applicationStatus,
        ];
    }

    private function parseDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false ||
            ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
            $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Assessment date and time must use YYYY-MM-DD HH:MM:SS.');
        }
        return $date;
    }
}
