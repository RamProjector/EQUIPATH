<?php
declare(strict_types=1);

/**
 * Assigns an active evaluator to an eligible application.
 *
 * The HTTP caller must authenticate, validate CSRF, and enforce the
 * coordinator role. All assignment, status, audit and notification writes are
 * committed together so a partial assignment cannot be exposed.
 */
final class EvaluatorAssignmentService
{
    /**
     * @return array{evaluator_assignment_id:int,application_id:int,evaluator_user_id:int,application_status:string}
     */
    public function assign(mysqli $db, int $coordinatorUserId, int $applicationId, int $evaluatorUserId): array
    {
        if ($coordinatorUserId < 1 || $applicationId < 1 || $evaluatorUserId < 1) {
            throw new InvalidArgumentException('Positive coordinator, application, and evaluator identifiers are required.');
        }

        $db->begin_transaction();
        try {
            $findApplication = $db->prepare(
                'SELECT application_number, applicant_user_id, application_status
                 FROM applications
                 WHERE application_id = ?
                 FOR UPDATE'
            );
            $findApplication->bind_param('i', $applicationId);
            $findApplication->execute();
            $application = $findApplication->get_result()->fetch_assoc();
            $findApplication->close();

            if (!is_array($application)) {
                throw new DomainException('The application is not available for evaluator assignment.');
            }

            $applicationStatus = (string) $application['application_status'];
            if (!in_array($applicationStatus, ['eligible', 'under_evaluation'], true)) {
                throw new DomainException('The application is not eligible for evaluation assignment.');
            }
            if ((int) $application['applicant_user_id'] === $evaluatorUserId) {
                throw new DomainException('An applicant cannot evaluate their own application.');
            }

            $findEvaluator = $db->prepare(
                "SELECT 1
                 FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.user_id
                 INNER JOIN roles r ON r.role_id = ur.role_id
                 WHERE u.user_id = ? AND u.account_status = 'active'
                   AND r.role_key = 'evaluator'
                 LIMIT 1"
            );
            $findEvaluator->bind_param('i', $evaluatorUserId);
            $findEvaluator->execute();
            $isActiveEvaluator = $findEvaluator->get_result()->num_rows === 1;
            $findEvaluator->close();
            if (!$isActiveEvaluator) {
                throw new DomainException('The selected account is not an active evaluator.');
            }

            $findExisting = $db->prepare(
                'SELECT 1 FROM evaluator_assignments
                 WHERE application_id = ? AND evaluator_user_id = ?
                 LIMIT 1'
            );
            $findExisting->bind_param('ii', $applicationId, $evaluatorUserId);
            $findExisting->execute();
            $alreadyAssigned = $findExisting->get_result()->num_rows === 1;
            $findExisting->close();
            if ($alreadyAssigned) {
                throw new DomainException('This evaluator already has an assignment for the application.');
            }

            $assignmentStatus = 'assigned';
            $insert = $db->prepare(
                'INSERT INTO evaluator_assignments
                    (application_id, evaluator_user_id, assigned_by, assignment_status)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->bind_param('iiis', $applicationId, $evaluatorUserId, $coordinatorUserId, $assignmentStatus);
            $insert->execute();
            $assignmentId = (int) $db->insert_id;
            $insert->close();

            if ($applicationStatus === 'eligible') {
                $nextStatus = 'under_evaluation';
                $update = $db->prepare(
                    'UPDATE applications SET application_status = ? WHERE application_id = ?'
                );
                $update->bind_param('si', $nextStatus, $applicationId);
                $update->execute();
                $update->close();

                $reason = 'Evaluator assigned; evaluation started';
                $history = $db->prepare(
                    'INSERT INTO application_status_history
                        (application_id, changed_by, from_status, to_status, reason)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $history->bind_param('iisss', $applicationId, $coordinatorUserId, $applicationStatus, $nextStatus, $reason);
                $history->execute();
                $history->close();
                $applicationStatus = $nextStatus;

                $applicantUserId = (int) $application['applicant_user_id'];
                $applicantNotificationType = 'application_status';
                $applicantTitle = 'Application status update';
                $applicantMessage = 'Your application has moved to the evaluation stage.';
                $applicantNotification = $db->prepare(
                    'INSERT INTO notifications
                        (recipient_user_id, notification_type, title, message, related_application_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $applicantNotification->bind_param(
                    'isssi',
                    $applicantUserId,
                    $applicantNotificationType,
                    $applicantTitle,
                    $applicantMessage,
                    $applicationId
                );
                $applicantNotification->execute();
                $applicantNotification->close();
            }

            $actionKey = 'evaluator.assigned';
            $entityType = 'application';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'evaluator_user_id' => $evaluatorUserId,
                'evaluator_assignment_id' => $assignmentId,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param(
                'ississ',
                $coordinatorUserId,
                $actionKey,
                $entityType,
                $applicationId,
                $auditOutcome,
                $metadata
            );
            $audit->execute();
            $audit->close();

            $evaluatorNotificationType = 'evaluator_assignment';
            $evaluatorTitle = 'Evaluation assignment';
            $applicationNumber = (string) $application['application_number'];
            $evaluatorMessage = 'You have been assigned to evaluate application ' . $applicationNumber . '. Sign in to review the assigned portfolio.';
            $evaluatorNotification = $db->prepare(
                'INSERT INTO notifications
                    (recipient_user_id, notification_type, title, message, related_application_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $evaluatorNotification->bind_param(
                'isssi',
                $evaluatorUserId,
                $evaluatorNotificationType,
                $evaluatorTitle,
                $evaluatorMessage,
                $applicationId
            );
            $evaluatorNotification->execute();
            $evaluatorNotification->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'evaluator_assignment_id' => $assignmentId,
            'application_id' => $applicationId,
            'evaluator_user_id' => $evaluatorUserId,
            'application_status' => $applicationStatus,
        ];
    }
}
