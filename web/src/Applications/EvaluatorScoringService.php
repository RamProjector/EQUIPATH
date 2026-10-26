<?php
declare(strict_types=1);

/**
 * Validates and persists evaluator rubric scores for an owned assignment.
 *
 * The HTTP layer must authenticate the evaluator, validate CSRF, and enforce
 * active evaluator role membership before calling this service.
 */
final class EvaluatorScoringService
{
    /**
     * Save scores as a draft or submit a complete rubric evaluation.
     *
     * Each score item has criterion_id:int, score:int|float|string and an
     * optional feedback:string. Submission requires every active rubric
     * criterion to be scored. Existing submissions cannot be changed.
     *
     * @param list<array{criterion_id:int,score:int|float|string,feedback?:string}> $scores
     * @return array{evaluator_assignment_id:int,application_id:int,assignment_status:string,criteria_scored:int,weighted_percentage:?float}
     */
    public function save(
        mysqli $db,
        int $evaluatorUserId,
        int $assignmentId,
        int $rubricId,
        array $scores,
        bool $submit
    ): array {
        if ($evaluatorUserId < 1 || $assignmentId < 1 || $rubricId < 1) {
            throw new InvalidArgumentException('Positive evaluator, assignment, and rubric identifiers are required.');
        }
        if ($scores === [] || count($scores) > 200) {
            throw new InvalidArgumentException('At least one score and no more than 200 score items are allowed.');
        }

        $db->begin_transaction();
        try {
            $assignmentQuery = $db->prepare(
                'SELECT ea.application_id, ea.evaluator_user_id, ea.assignment_status,
                        a.application_status, a.program_id, a.application_number
                 FROM evaluator_assignments ea
                 INNER JOIN applications a ON a.application_id = ea.application_id
                 WHERE ea.evaluator_assignment_id = ?
                 FOR UPDATE'
            );
            $assignmentQuery->bind_param('i', $assignmentId);
            $assignmentQuery->execute();
            $assignment = $assignmentQuery->get_result()->fetch_assoc();
            $assignmentQuery->close();

            if (!is_array($assignment) || (int) $assignment['evaluator_user_id'] !== $evaluatorUserId) {
                throw new DomainException('The evaluation assignment is not available to this evaluator.');
            }
            if (!in_array((string) $assignment['assignment_status'], ['assigned', 'in_progress'], true)) {
                throw new DomainException('This evaluation assignment is no longer editable.');
            }
            if ((string) $assignment['application_status'] !== 'under_evaluation') {
                throw new DomainException('The application is not in the evaluation stage.');
            }

            $applicationId = (int) $assignment['application_id'];
            $programId = (int) $assignment['program_id'];
            $rubricQuery = $db->prepare(
                'SELECT rubric_id
                 FROM evaluation_rubrics
                 WHERE rubric_id = ? AND program_id = ? AND is_active = 1
                 FOR UPDATE'
            );
            $rubricQuery->bind_param('ii', $rubricId, $programId);
            $rubricQuery->execute();
            $rubricExists = $rubricQuery->get_result()->num_rows === 1;
            $rubricQuery->close();
            if (!$rubricExists) {
                throw new DomainException('The rubric is not active for this application program.');
            }

            $criteriaQuery = $db->prepare(
                'SELECT criterion_id, max_score, weight
                 FROM rubric_criteria
                 WHERE rubric_id = ?
                 ORDER BY sort_order, criterion_id
                 FOR UPDATE'
            );
            $criteriaQuery->bind_param('i', $rubricId);
            $criteriaQuery->execute();
            $criteriaResult = $criteriaQuery->get_result();
            $criteria = [];
            $weightTotal = 0.0;
            while ($row = $criteriaResult->fetch_assoc()) {
                $criterionId = (int) $row['criterion_id'];
                $criteria[$criterionId] = [
                    'max_score' => (float) $row['max_score'],
                    'weight' => (float) $row['weight'],
                ];
                $weightTotal += (float) $row['weight'];
            }
            $criteriaQuery->close();
            if ($criteria === [] || $weightTotal <= 0.0) {
                throw new DomainException('The rubric has no valid scoring criteria.');
            }

            // An assignment cannot silently switch rubric versions halfway
            // through scoring; create a new assignment if its active rubric changes.
            $existingQuery = $db->prepare(
                'SELECT DISTINCT rc.rubric_id
                 FROM evaluation_scores es
                 INNER JOIN rubric_criteria rc ON rc.criterion_id = es.criterion_id
                 WHERE es.evaluator_assignment_id = ?'
            );
            $existingQuery->bind_param('i', $assignmentId);
            $existingQuery->execute();
            $existingResult = $existingQuery->get_result();
            while ($existingRow = $existingResult->fetch_assoc()) {
                if ((int) $existingRow['rubric_id'] !== $rubricId) {
                    $existingQuery->close();
                    throw new DomainException('This assignment already contains scores from a different rubric version.');
                }
            }
            $existingQuery->close();

            $normalizedScores = [];
            foreach ($scores as $scoreItem) {
                if (!is_array($scoreItem) ||
                    !isset($scoreItem['criterion_id'], $scoreItem['score']) ||
                    !is_int($scoreItem['criterion_id']) ||
                    $scoreItem['criterion_id'] < 1 ||
                    (!is_int($scoreItem['score']) && !is_float($scoreItem['score']) && !is_string($scoreItem['score'])) ||
                    (isset($scoreItem['feedback']) && !is_string($scoreItem['feedback']))) {
                    throw new InvalidArgumentException('A score item has invalid fields.');
                }

                $criterionId = $scoreItem['criterion_id'];
                if (!isset($criteria[$criterionId])) {
                    throw new InvalidArgumentException('Every criterion must belong to the selected active rubric.');
                }
                if (isset($normalizedScores[$criterionId])) {
                    throw new InvalidArgumentException('A criterion can only be scored once per request.');
                }

                $scoreText = (string) $scoreItem['score'];
                if (!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $scoreText)) {
                    throw new InvalidArgumentException('Scores must be non-negative numbers with at most two decimal places.');
                }
                $scoreValue = (float) $scoreText;
                if ($scoreValue > $criteria[$criterionId]['max_score']) {
                    throw new InvalidArgumentException('A score exceeds its criterion maximum.');
                }

                $feedback = $scoreItem['feedback'] ?? '';
                if (strlen($feedback) > 4000) {
                    throw new InvalidArgumentException('Criterion feedback cannot exceed 4000 bytes.');
                }

                $normalizedScores[$criterionId] = [
                    'score' => number_format($scoreValue, 2, '.', ''),
                    'feedback' => $feedback,
                ];
            }

            if ($submit && count($normalizedScores) !== count($criteria)) {
                throw new InvalidArgumentException('A submitted evaluation must score every rubric criterion.');
            }

            $upsert = $db->prepare(
                'INSERT INTO evaluation_scores
                    (evaluator_assignment_id, criterion_id, score, feedback)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    score = VALUES(score),
                    feedback = VALUES(feedback),
                    scored_at = CURRENT_TIMESTAMP'
            );
            foreach ($normalizedScores as $criterionId => $item) {
                $scoreText = $item['score'];
                $feedback = $item['feedback'];
                $upsert->bind_param('iiss', $assignmentId, $criterionId, $scoreText, $feedback);
                $upsert->execute();
            }
            $upsert->close();

            $weightedPercentage = null;
            if ($submit) {
                $weightedTotal = 0.0;
                foreach ($criteria as $criterionId => $criterion) {
                    $weightedTotal += (
                        (float) $normalizedScores[$criterionId]['score'] / $criterion['max_score']
                    ) * $criterion['weight'];
                }
                $weightedPercentage = round(($weightedTotal / $weightTotal) * 100, 2);

                $nextStatus = 'submitted';
                $update = $db->prepare(
                    'UPDATE evaluator_assignments
                     SET assignment_status = ?, submitted_at = CURRENT_TIMESTAMP
                     WHERE evaluator_assignment_id = ? AND evaluator_user_id = ?'
                );
                $update->bind_param('sii', $nextStatus, $assignmentId, $evaluatorUserId);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    $update->close();
                    throw new RuntimeException('The evaluation assignment could not be submitted.');
                }
                $update->close();

                $actionKey = 'evaluation.submitted';
                $entityType = 'evaluator_assignment';
                $auditOutcome = 'success';
                $metadata = json_encode([
                    'rubric_id' => $rubricId,
                    'criteria_scored' => count($normalizedScores),
                    'weighted_percentage' => $weightedPercentage,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $audit = $db->prepare(
                    'INSERT INTO audit_logs
                        (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $audit->bind_param(
                    'ississ',
                    $evaluatorUserId,
                    $actionKey,
                    $entityType,
                    $assignmentId,
                    $auditOutcome,
                    $metadata
                );
                $audit->execute();
                $audit->close();

                $coordinatorRole = $db->prepare(
                    "SELECT DISTINCT u.user_id
                     FROM users u
                     INNER JOIN user_roles ur ON ur.user_id = u.user_id
                     INNER JOIN roles r ON r.role_id = ur.role_id
                     WHERE u.account_status = 'active' AND r.role_key = 'coordinator'"
                );
                $coordinatorRole->execute();
                $coordinators = $coordinatorRole->get_result();
                $notificationType = 'evaluation_submitted';
                $notificationTitle = 'Evaluation submitted';
                $notificationMessage = 'Evaluation submitted for application ' . (string) $assignment['application_number'] . '.';
                $notification = $db->prepare(
                    'INSERT INTO notifications
                        (recipient_user_id, notification_type, title, message, related_application_id)
                     VALUES (?, ?, ?, ?, ?)'
                );
                while ($coordinator = $coordinators->fetch_assoc()) {
                    $coordinatorUserId = (int) $coordinator['user_id'];
                    $notification->bind_param(
                        'isssi',
                        $coordinatorUserId,
                        $notificationType,
                        $notificationTitle,
                        $notificationMessage,
                        $applicationId
                    );
                    $notification->execute();
                }
                $notification->close();
                $coordinatorRole->close();
            } else {
                $nextStatus = 'in_progress';
                $update = $db->prepare(
                    'UPDATE evaluator_assignments
                     SET assignment_status = ?
                     WHERE evaluator_assignment_id = ? AND evaluator_user_id = ?'
                );
                $update->bind_param('sii', $nextStatus, $assignmentId, $evaluatorUserId);
                $update->execute();
                $update->close();
            }

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'evaluator_assignment_id' => $assignmentId,
            'application_id' => $applicationId,
            'assignment_status' => $submit ? 'submitted' : 'in_progress',
            'criteria_scored' => count($normalizedScores),
            'weighted_percentage' => $weightedPercentage,
        ];
    }
}
