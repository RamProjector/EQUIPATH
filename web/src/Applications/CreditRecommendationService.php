<?php
declare(strict_types=1);

/**
 * Persists course-credit recommendations for a submitted evaluator assignment.
 *
 * Callers must authenticate the evaluator and enforce active evaluator role
 * membership before invoking this service.
 */
final class CreditRecommendationService
{
    /**
     * @param list<array{course_code:string,course_title:string,recommended_credits:int|float|string,rationale:string}> $recommendations
     * @return array{evaluator_assignment_id:int,application_id:int,recommendation_ids:list<int>,count:int}
     */
    public function submit(
        mysqli $db,
        int $evaluatorUserId,
        int $assignmentId,
        array $recommendations
    ): array {
        if ($evaluatorUserId < 1 || $assignmentId < 1) {
            throw new InvalidArgumentException('Positive evaluator and assignment identifiers are required.');
        }
        if ($recommendations === [] || count($recommendations) > 100) {
            throw new InvalidArgumentException('Provide between one and 100 credit recommendations.');
        }

        $normalized = [];
        $codes = [];
        foreach ($recommendations as $recommendation) {
            if (!is_array($recommendation) ||
                !is_string($recommendation['course_code'] ?? null) ||
                !is_string($recommendation['course_title'] ?? null) ||
                (!is_int($recommendation['recommended_credits'] ?? null) &&
                 !is_float($recommendation['recommended_credits'] ?? null) &&
                 !is_string($recommendation['recommended_credits'] ?? null)) ||
                !is_string($recommendation['rationale'] ?? null)) {
                throw new InvalidArgumentException('A recommendation contains invalid fields.');
            }

            $courseCode = strtoupper(trim($recommendation['course_code']));
            $courseTitle = trim($recommendation['course_title']);
            $creditsText = (string) $recommendation['recommended_credits'];
            $rationale = trim($recommendation['rationale']);
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/D', $courseCode)) {
                throw new InvalidArgumentException('Course code contains unsupported characters or exceeds 40 characters.');
            }
            if ($courseTitle === '' || strlen($courseTitle) > 180) {
                throw new InvalidArgumentException('Course title must contain 1 to 180 bytes.');
            }
            if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $creditsText) || (float) $creditsText > 9999.99) {
                throw new InvalidArgumentException('Recommended credits must be non-negative with at most two decimal places.');
            }
            if ($rationale === '' || strlen($rationale) > 4000) {
                throw new InvalidArgumentException('Rationale must contain 1 to 4000 bytes.');
            }
            if (isset($codes[$courseCode])) {
                throw new InvalidArgumentException('A course code can only appear once in a submission.');
            }
            $codes[$courseCode] = true;
            $normalized[] = [
                'course_code' => $courseCode,
                'course_title' => $courseTitle,
                'recommended_credits' => number_format((float) $creditsText, 2, '.', ''),
                'rationale' => $rationale,
            ];
        }

        $db->begin_transaction();
        try {
            $assignmentQuery = $db->prepare(
                'SELECT ea.application_id, ea.evaluator_user_id, ea.assignment_status,
                        a.application_status, a.application_number
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
                throw new DomainException('The assignment is not available to this evaluator.');
            }
            if ((string) $assignment['assignment_status'] !== 'submitted') {
                throw new DomainException('Credit recommendations require a submitted evaluation.');
            }
            if (!in_array((string) $assignment['application_status'], ['under_evaluation', 'assessment_scheduled', 'awaiting_decision'], true)) {
                throw new DomainException('The application is not accepting credit recommendations.');
            }

            $existingQuery = $db->prepare(
                'SELECT course_code FROM credit_recommendations
                 WHERE evaluator_assignment_id = ? FOR UPDATE'
            );
            $existingQuery->bind_param('i', $assignmentId);
            $existingQuery->execute();
            $existingResult = $existingQuery->get_result();
            while ($row = $existingResult->fetch_assoc()) {
                $existingCode = strtoupper((string) $row['course_code']);
                if (isset($codes[$existingCode])) {
                    $existingQuery->close();
                    throw new DomainException('A recommendation for one or more course codes already exists on this assignment.');
                }
            }
            $existingQuery->close();

            $insert = $db->prepare(
                'INSERT INTO credit_recommendations
                    (application_id, evaluator_assignment_id, course_code, course_title, recommended_credits, rationale)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $applicationId = (int) $assignment['application_id'];
            $recommendationIds = [];
            foreach ($normalized as $item) {
                $courseCode = $item['course_code'];
                $courseTitle = $item['course_title'];
                $credits = $item['recommended_credits'];
                $rationale = $item['rationale'];
                $insert->bind_param('iissss', $applicationId, $assignmentId, $courseCode, $courseTitle, $credits, $rationale);
                $insert->execute();
                $recommendationIds[] = (int) $db->insert_id;
            }
            $insert->close();

            $actionKey = 'credit_recommendations.submitted';
            $entityType = 'evaluator_assignment';
            $auditOutcome = 'success';
            $courseCodes = array_column($normalized, 'course_code');
            $metadata = json_encode([
                'recommendation_count' => count($recommendationIds),
                'course_codes' => $courseCodes,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs
                    (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $evaluatorUserId, $actionKey, $entityType, $assignmentId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $coordinatorQuery = $db->prepare(
                "SELECT DISTINCT u.user_id
                 FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.user_id
                 INNER JOIN roles r ON r.role_id = ur.role_id
                 WHERE u.account_status = 'active' AND r.role_key = 'coordinator'"
            );
            $coordinatorQuery->execute();
            $coordinators = $coordinatorQuery->get_result();
            $notificationType = 'credit_recommendations';
            $notificationTitle = 'Credit recommendations submitted';
            $notificationMessage = 'Credit recommendations were submitted for application ' . (string) $assignment['application_number'] . '.';
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
            $coordinatorQuery->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'evaluator_assignment_id' => $assignmentId,
            'application_id' => $applicationId,
            'recommendation_ids' => $recommendationIds,
            'count' => count($recommendationIds),
        ];
    }
}
