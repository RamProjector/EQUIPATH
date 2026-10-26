<?php
declare(strict_types=1);

/**
 * Records immutable accreditation decisions and approved credits.
 *
 * HTTP callers must authenticate and enforce the approver role. Revisions
 * append a new decision that explicitly supersedes the current decision.
 */
final class AccreditationDecisionService
{
    /**
     * @param list<array{course_code:string,course_title:string,approved_credits:int|float|string}> $approvedCredits
     * @return array{accreditation_decision_id:int,application_id:int,decision:string,application_status:string,approved_credit_count:int,supersedes_decision_id:?int}
     */
    public function decide(
        mysqli $db,
        int $approverUserId,
        int $applicationId,
        string $decision,
        string $rationale,
        ?int $supersedesDecisionId,
        array $approvedCredits
    ): array {
        if ($approverUserId < 1 || $applicationId < 1) {
            throw new InvalidArgumentException('Positive approver and application identifiers are required.');
        }
        if (!in_array($decision, ['approved', 'rejected', 'deferred', 'returned_for_revision'], true)) {
            throw new InvalidArgumentException('Unsupported accreditation decision.');
        }
        $rationale = trim($rationale);
        if ($rationale === '' || strlen($rationale) > 4000) {
            throw new InvalidArgumentException('Decision rationale must contain 1 to 4000 bytes.');
        }
        if ($supersedesDecisionId !== null && $supersedesDecisionId < 1) {
            throw new InvalidArgumentException('Superseded decision identifier must be positive.');
        }
        if ($decision === 'approved' && $approvedCredits === []) {
            throw new InvalidArgumentException('An approval must include at least one approved credit.');
        }
        if ($decision !== 'approved' && $approvedCredits !== []) {
            throw new InvalidArgumentException('Approved credits can only be recorded with an approval decision.');
        }
        if (count($approvedCredits) > 200) {
            throw new InvalidArgumentException('No more than 200 approved credit rows can be recorded at once.');
        }

        $normalizedCredits = [];
        $seenCodes = [];
        foreach ($approvedCredits as $credit) {
            if (!is_array($credit) ||
                !is_string($credit['course_code'] ?? null) ||
                !is_string($credit['course_title'] ?? null) ||
                (!is_int($credit['approved_credits'] ?? null) &&
                 !is_float($credit['approved_credits'] ?? null) &&
                 !is_string($credit['approved_credits'] ?? null))) {
                throw new InvalidArgumentException('An approved credit row contains invalid fields.');
            }
            $courseCode = strtoupper(trim($credit['course_code']));
            $courseTitle = trim($credit['course_title']);
            $creditsText = (string) $credit['approved_credits'];
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/D', $courseCode)) {
                throw new InvalidArgumentException('Course code contains unsupported characters or exceeds 40 characters.');
            }
            if ($courseTitle === '' || strlen($courseTitle) > 180) {
                throw new InvalidArgumentException('Course title must contain 1 to 180 bytes.');
            }
            if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $creditsText) || (float) $creditsText > 9999.99) {
                throw new InvalidArgumentException('Approved credits must be non-negative with at most two decimal places.');
            }
            if (isset($seenCodes[$courseCode])) {
                throw new InvalidArgumentException('A course code can only be approved once per decision.');
            }
            $seenCodes[$courseCode] = true;
            $normalizedCredits[] = [
                'course_code' => $courseCode,
                'course_title' => $courseTitle,
                'approved_credits' => number_format((float) $creditsText, 2, '.', ''),
            ];
        }

        $db->begin_transaction();
        try {
            $applicationQuery = $db->prepare(
                'SELECT applicant_user_id, application_number, application_status
                 FROM applications WHERE application_id = ? FOR UPDATE'
            );
            $applicationQuery->bind_param('i', $applicationId);
            $applicationQuery->execute();
            $application = $applicationQuery->get_result()->fetch_assoc();
            $applicationQuery->close();
            if (!is_array($application)) {
                throw new DomainException('The application is not available for an accreditation decision.');
            }

            $decisionQuery = $db->prepare(
                'SELECT accreditation_decision_id, supersedes_decision_id, decision
                 FROM accreditation_decisions
                 WHERE application_id = ?
                 ORDER BY decided_at, accreditation_decision_id
                 FOR UPDATE'
            );
            $decisionQuery->bind_param('i', $applicationId);
            $decisionQuery->execute();
            $decisionRows = $decisionQuery->get_result();
            $decisions = [];
            $supersededIds = [];
            while ($row = $decisionRows->fetch_assoc()) {
                $id = (int) $row['accreditation_decision_id'];
                $supersedes = $row['supersedes_decision_id'] === null ? null : (int) $row['supersedes_decision_id'];
                $decisions[$id] = [
                    'decision' => (string) $row['decision'],
                    'supersedes' => $supersedes,
                ];
                if ($supersedes !== null) {
                    $supersededIds[$supersedes] = true;
                }
            }
            $decisionQuery->close();

            $currentIds = [];
            foreach (array_keys($decisions) as $id) {
                if (!isset($supersededIds[$id])) {
                    $currentIds[] = $id;
                }
            }
            if (count($currentIds) > 1) {
                throw new DomainException('The application has multiple current decisions; manual reconciliation is required.');
            }
            $currentDecisionId = $currentIds[0] ?? null;
            $applicationStatus = (string) $application['application_status'];

            if ($decisions !== [] && $currentDecisionId === null) {
                throw new DomainException('The application has decision history but no current decision; manual reconciliation is required.');
            }
            if ($currentDecisionId === null) {
                if ($supersedesDecisionId !== null || $applicationStatus !== 'awaiting_decision') {
                    throw new DomainException('A first decision requires an application awaiting decision and no existing decision.');
                }
            } else {
                if ($supersedesDecisionId !== $currentDecisionId) {
                    throw new DomainException('An existing decision can only be changed by explicitly superseding the current decision.');
                }
                if (!in_array($applicationStatus, ['awaiting_decision', 'approved', 'rejected', 'needs_correction'], true)) {
                    throw new DomainException('The application is not in a state that permits a superseding decision.');
                }
            }

            if ($decision === 'approved') {
                foreach ($normalizedCredits as $credit) {
                    $recommendationQuery = $db->prepare(
                        'SELECT COUNT(*) AS total, MAX(recommended_credits) AS maximum_recommended
                         FROM credit_recommendations cr
                         INNER JOIN evaluator_assignments ea ON ea.evaluator_assignment_id = cr.evaluator_assignment_id
                         WHERE cr.application_id = ? AND UPPER(cr.course_code) = ? AND cr.course_title = ?
                           AND ea.assignment_status = \'submitted\''
                    );
                    $courseCode = $credit['course_code'];
                    $courseTitle = $credit['course_title'];
                    $recommendationQuery->bind_param('iss', $applicationId, $courseCode, $courseTitle);
                    $recommendationQuery->execute();
                    $recommendation = $recommendationQuery->get_result()->fetch_assoc();
                    $recommendationQuery->close();

                    if ((int) ($recommendation['total'] ?? 0) < 1) {
                        throw new DomainException('Every approved course must match a submitted recommendation for this application.');
                    }
                    if ((float) $credit['approved_credits'] > (float) $recommendation['maximum_recommended']) {
                        throw new DomainException('Approved credits cannot exceed the highest submitted recommendation for that course.');
                    }
                }
            }

            $insert = $db->prepare(
                'INSERT INTO accreditation_decisions
                    (application_id, decided_by, decision, rationale, supersedes_decision_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->bind_param('iissi', $applicationId, $approverUserId, $decision, $rationale, $supersedesDecisionId);
            $insert->execute();
            $decisionId = (int) $db->insert_id;
            $insert->close();

            $approvedCreditCount = 0;
            if ($decision === 'approved') {
                $creditInsert = $db->prepare(
                    'INSERT INTO accredited_credits
                        (accreditation_decision_id, course_code, course_title, approved_credits)
                     VALUES (?, ?, ?, ?)'
                );
                foreach ($normalizedCredits as $credit) {
                    $courseCode = $credit['course_code'];
                    $courseTitle = $credit['course_title'];
                    $approvedCreditsText = $credit['approved_credits'];
                    $creditInsert->bind_param('isss', $decisionId, $courseCode, $courseTitle, $approvedCreditsText);
                    $creditInsert->execute();
                    $approvedCreditCount++;
                }
                $creditInsert->close();
            }

            $nextStatus = match ($decision) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'returned_for_revision' => 'needs_correction',
                default => 'awaiting_decision',
            };
            if ($nextStatus !== $applicationStatus) {
                $update = $db->prepare(
                    'UPDATE applications SET application_status = ? WHERE application_id = ?'
                );
                $update->bind_param('si', $nextStatus, $applicationId);
                $update->execute();
                $update->close();

                $reason = 'Accreditation decision recorded: ' . $decision;
                $history = $db->prepare(
                    'INSERT INTO application_status_history
                        (application_id, changed_by, from_status, to_status, reason)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $history->bind_param('iisss', $applicationId, $approverUserId, $applicationStatus, $nextStatus, $reason);
                $history->execute();
                $history->close();
            }

            $actionKey = $currentDecisionId === null ? 'accreditation.decision_recorded' : 'accreditation.decision_superseded';
            $entityType = 'accreditation_decision';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'application_id' => $applicationId,
                'decision' => $decision,
                'supersedes_decision_id' => $supersedesDecisionId,
                'approved_credit_count' => $approvedCreditCount,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $approverUserId, $actionKey, $entityType, $decisionId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $notificationType = 'accreditation_decision';
            $notificationTitle = match ($decision) {
                'approved' => 'Application approved',
                'rejected' => 'Application decision recorded',
                'deferred' => 'Application decision deferred',
                default => 'Application returned for revision',
            };
            $notificationMessage = match ($decision) {
                'approved' => 'Your application has been approved. Sign in to review the decision and approved credits.',
                'rejected' => 'A decision has been recorded for your application. Sign in to review the official decision.',
                'deferred' => 'Your application decision has been deferred for further review.',
                default => 'Your application was returned for revision. Sign in to review the next steps.',
            };
            $applicantUserId = (int) $application['applicant_user_id'];
            $notification = $db->prepare(
                'INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $notification->bind_param('isssi', $applicantUserId, $notificationType, $notificationTitle, $notificationMessage, $applicationId);
            $notification->execute();
            $notification->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'accreditation_decision_id' => $decisionId,
            'application_id' => $applicationId,
            'decision' => $decision,
            'application_status' => $nextStatus,
            'approved_credit_count' => $approvedCreditCount,
            'supersedes_decision_id' => $supersedesDecisionId,
        ];
    }
}
