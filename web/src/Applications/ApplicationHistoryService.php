<?php
declare(strict_types=1);

/** Exposes only the signed-in applicant's own workflow history and applicant-facing screening feedback. */
final class ApplicationHistoryService
{
    /** @return array<string,mixed> */
    public function getForApplicant(mysqli $db, int $userId, int $applicationId): array
    {
        if ($userId < 1 || $applicationId < 1) {
            throw new InvalidArgumentException('Positive applicant and application identifiers are required.');
        }

        $applicationQuery = $db->prepare(
            'SELECT a.application_id, a.application_number, a.application_status,
                    a.submitted_at, a.updated_at, p.program_code, p.program_name
             FROM applications a
             INNER JOIN academic_programs p ON p.program_id = a.program_id
             WHERE a.application_id = ? AND a.applicant_user_id = ? LIMIT 1'
        );
        $applicationQuery->bind_param('ii', $applicationId, $userId);
        $applicationQuery->execute();
        $application = $applicationQuery->get_result()->fetch_assoc();
        $applicationQuery->close();
        if (!is_array($application)) {
            throw new DomainException('Application history is not available to this account.');
        }

        $historyQuery = $db->prepare(
            'SELECT from_status, to_status, reason, changed_at
             FROM application_status_history
             WHERE application_id = ?
             ORDER BY changed_at DESC, status_history_id DESC LIMIT 100'
        );
        $historyQuery->bind_param('i', $applicationId);
        $historyQuery->execute();
        $historyResult = $historyQuery->get_result();
        $statusHistory = [];
        while ($row = $historyResult->fetch_assoc()) {
            $statusHistory[] = [
                'from_status' => $row['from_status'] === null ? null : (string) $row['from_status'],
                'to_status' => (string) $row['to_status'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'changed_at' => (string) $row['changed_at'],
            ];
        }
        $historyQuery->close();

        $screeningQuery = $db->prepare(
            'SELECT outcome, notes, reviewed_at, created_at
             FROM screening_reviews
             WHERE application_id = ? AND outcome <> \'pending\'
             ORDER BY reviewed_at DESC, screening_review_id DESC LIMIT 100'
        );
        $screeningQuery->bind_param('i', $applicationId);
        $screeningQuery->execute();
        $screeningResult = $screeningQuery->get_result();
        $screeningFeedback = [];
        while ($row = $screeningResult->fetch_assoc()) {
            $screeningFeedback[] = [
                'outcome' => (string) $row['outcome'],
                'notes' => $row['notes'] === null ? '' : (string) $row['notes'],
                'reviewed_at' => $row['reviewed_at'] === null ? (string) $row['created_at'] : (string) $row['reviewed_at'],
            ];
        }
        $screeningQuery->close();

        return [
            'application' => [
                'application_id' => (int) $application['application_id'],
                'application_number' => (string) $application['application_number'],
                'application_status' => (string) $application['application_status'],
                'submitted_at' => $application['submitted_at'] === null ? null : (string) $application['submitted_at'],
                'updated_at' => (string) $application['updated_at'],
                'program' => [
                    'code' => (string) $application['program_code'],
                    'name' => (string) $application['program_name'],
                ],
            ],
            'status_history' => $statusHistory,
            'screening_feedback' => $screeningFeedback,
            'history_limit' => 100,
        ];
    }
}
