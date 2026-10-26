<?php
declare(strict_types=1);

/**
 * Saves one criterion score for the evaluator who owns the assignment.
 *
 * The HTTP caller must authenticate, validate CSRF, and enforce the evaluator
 * role. This service rechecks assignment ownership and the active program rubric
 * inside the transaction; scores cannot be written after assignment submission.
 */
final class EvaluationScoringService
{
    /**
     * @return array{evaluation_score_id:int,evaluator_assignment_id:int,criterion_id:int,score:float,assignment_status:string}
     */
    public function saveScore(
        mysqli $db,
        int $evaluatorUserId,
        int $assignmentId,
        int $criterionId,
        float $score,
        string $feedback
    ): array {
        if ($evaluatorUserId < 1 || $assignmentId < 1 || $criterionId < 1) {
            throw new InvalidArgumentException('Positive evaluator, assignment, and criterion identifiers are required.');
        }
        if (!is_finite($score) || $score < 0 ||
            abs(($score * 100) - round($score * 100)) > 0.000001) {
            throw new InvalidArgumentException('Score must be a finite, non-negative number with at most two decimal places.');
        }
        if (strlen($feedback) > 4000 || str_contains($feedback, "\0")) {
            throw new InvalidArgumentException('Feedback must be at most 4000 bytes and contain no NUL bytes.');
        }

        $db->begin_transaction();
        try {
            $findAssignment = $db->prepare(
                'SELECT ea.application_id, ea.evaluator_user_id, ea.assignment_status,
                        a.program_id, a.application_status
                 FROM evaluator_assignments ea
                 INNER JOIN applications a ON a.application_id = ea.application_id
                 WHERE ea.evaluator_assignment_id = ?
                 FOR UPDATE'
            );
            $findAssignment->bind_param('i', $assignmentId);
            $findAssignment->execute();
            $assignment = $findAssignment->get_result()->fetch_assoc();
            $findAssignment->close();

            if (!is_array($assignment) ||
                (int) $assignment['evaluator_user_id'] !== $evaluatorUserId ||
                !in_array($assignment['assignment_status'], ['assigned', 'in_progress'], true) ||
                $assignment['application_status'] !== 'under_evaluation') {
                throw new DomainException('The evaluator assignment is not available for scoring.');
            }

            $programId = (int) $assignment['program_id'];
            $activeRubric = $db->prepare(
                'SELECT rubric_id FROM evaluation_rubrics
                 WHERE program_id = ? AND is_active = TRUE
                 ORDER BY created_at DESC, rubric_id DESC
                 LIMIT 1'
            );
            $activeRubric->bind_param('i', $programId);
            $activeRubric->execute();
            $rubricRow = $activeRubric->get_result()->fetch_assoc();
            $activeRubric->close();
            if (!is_array($rubricRow)) {
                throw new DomainException('No active evaluation rubric is configured for this program.');
            }
            $activeRubricId = (int) $rubricRow['rubric_id'];

            $findCriterion = $db->prepare(
                'SELECT rubric_id, max_score FROM rubric_criteria WHERE criterion_id = ? LIMIT 1'
            );
            $findCriterion->bind_param('i', $criterionId);
            $findCriterion->execute();
            $criterion = $findCriterion->get_result()->fetch_assoc();
            $findCriterion->close();
            if (!is_array($criterion) || (int) $criterion['rubric_id'] !== $activeRubricId) {
                throw new DomainException('The criterion does not belong to the active rubric for this application.');
            }

            $maximumScore = (float) $criterion['max_score'];
            if ($score > $maximumScore) {
                throw new DomainException('The score exceeds the criterion maximum.');
            }

            $findExisting = $db->prepare(
                'SELECT evaluation_score_id
                 FROM evaluation_scores
                 WHERE evaluator_assignment_id = ? AND criterion_id = ?
                 FOR UPDATE'
            );
            $findExisting->bind_param('ii', $assignmentId, $criterionId);
            $findExisting->execute();
            $existing = $findExisting->get_result()->fetch_assoc();
            $findExisting->close();

            if (is_array($existing)) {
                $scoreId = (int) $existing['evaluation_score_id'];
                $update = $db->prepare(
                    'UPDATE evaluation_scores
                     SET score = ?, feedback = ?, scored_at = CURRENT_TIMESTAMP
                     WHERE evaluation_score_id = ?'
                );
                $update->bind_param('dsi', $score, $feedback, $scoreId);
                $update->execute();
                $update->close();
            } else {
                $insert = $db->prepare(
                    'INSERT INTO evaluation_scores
                        (evaluator_assignment_id, criterion_id, score, feedback)
                     VALUES (?, ?, ?, ?)'
                );
                $insert->bind_param('iids', $assignmentId, $criterionId, $score, $feedback);
                $insert->execute();
                $scoreId = (int) $db->insert_id;
                $insert->close();
            }

            $assignmentStatus = (string) $assignment['assignment_status'];
            if ($assignmentStatus === 'assigned') {
                $assignmentStatus = 'in_progress';
                $updateAssignment = $db->prepare(
                    'UPDATE evaluator_assignments SET assignment_status = ? WHERE evaluator_assignment_id = ?'
                );
                $updateAssignment->bind_param('si', $assignmentStatus, $assignmentId);
                $updateAssignment->execute();
                $updateAssignment->close();
            }

            $actionKey = 'evaluation.score_saved';
            $entityType = 'evaluation_score';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'evaluator_assignment_id' => $assignmentId,
                'criterion_id' => $criterionId,
                'score' => round($score, 2),
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
                $scoreId,
                $auditOutcome,
                $metadata
            );
            $audit->execute();
            $audit->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'evaluation_score_id' => $scoreId,
            'evaluator_assignment_id' => $assignmentId,
            'criterion_id' => $criterionId,
            'score' => round($score, 2),
            'assignment_status' => $assignmentStatus,
        ];
    }
}
