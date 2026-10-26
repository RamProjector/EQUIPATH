<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database/ConnectionFactory.php';
require_once dirname(__DIR__) . '/src/Security/SessionManager.php';
require_once dirname(__DIR__) . '/src/Security/AccessControl.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}
$applicationId = filter_input(INPUT_GET, 'application_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($applicationId) || $applicationId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_application_details']);
    exit;
}

$db = null;
try {
    $sessions = new SessionManager();
    $sessions->start();
    $access = new AccessControl();
    $userId = $access->requireAuthenticated();
    $db = ConnectionFactory::createFromEnvironment();
    $roles = $access->requireAnyRole($db, ['applicant', 'coordinator', 'approver']);
    $isApprover = in_array('approver', $roles, true);
    $isStaff = $isApprover || in_array('coordinator', $roles, true);
    $isApplicant = in_array('applicant', $roles, true);

    $applicationQuery = $db->prepare(
        "SELECT a.application_id, a.applicant_user_id, a.application_number, a.application_status,
                p.program_code, p.program_name,
                (SELECT COUNT(*)
                 FROM accreditation_decisions d
                 WHERE d.application_id = a.application_id
                   AND NOT EXISTS (
                     SELECT 1 FROM accreditation_decisions newer
                     WHERE newer.supersedes_decision_id = d.accreditation_decision_id
                   )) AS current_decision_count,
                (SELECT d.accreditation_decision_id
                 FROM accreditation_decisions d
                 WHERE d.application_id = a.application_id
                   AND NOT EXISTS (
                     SELECT 1 FROM accreditation_decisions newer
                     WHERE newer.supersedes_decision_id = d.accreditation_decision_id
                   )
                 ORDER BY d.decided_at DESC, d.accreditation_decision_id DESC
                 LIMIT 1) AS current_decision_id
         FROM applications a
         INNER JOIN academic_programs p ON p.program_id = a.program_id
         WHERE a.application_id = ?
         LIMIT 1"
    );
    $applicationQuery->bind_param('i', $applicationId);
    $applicationQuery->execute();
    $application = $applicationQuery->get_result()->fetch_assoc();
    $applicationQuery->close();

    if (!is_array($application) ||
        (!$isStaff && (!$isApplicant || (int) $application['applicant_user_id'] !== $userId))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'application_not_found']);
        exit;
    }

    $currentCount = (int) $application['current_decision_count'];
    if ($currentCount > 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'decision_history_inconsistent']);
        exit;
    }
    $currentDecisionId = $application['current_decision_id'] === null ? null : (int) $application['current_decision_id'];

    if ($isStaff) {
        $decisionQuery = $db->prepare(
            "SELECT d.accreditation_decision_id, d.decision, d.rationale, d.decided_at,
                    d.supersedes_decision_id, d.decided_by,
                    CASE WHEN EXISTS (
                      SELECT 1 FROM accreditation_decisions newer
                      WHERE newer.supersedes_decision_id = d.accreditation_decision_id
                    ) THEN 0 ELSE 1 END AS is_current
             FROM accreditation_decisions d
             WHERE d.application_id = ?
             ORDER BY d.decided_at DESC, d.accreditation_decision_id DESC
             LIMIT 100"
        );
    } else {
        $decisionQuery = $db->prepare(
            "SELECT d.accreditation_decision_id, d.decision, d.rationale, d.decided_at,
                    d.supersedes_decision_id, d.decided_by, 1 AS is_current
             FROM accreditation_decisions d
             WHERE d.application_id = ?
               AND NOT EXISTS (
                 SELECT 1 FROM accreditation_decisions newer
                 WHERE newer.supersedes_decision_id = d.accreditation_decision_id
               )
             ORDER BY d.decided_at DESC, d.accreditation_decision_id DESC
             LIMIT 1"
        );
    }
    $decisionQuery->bind_param('i', $applicationId);
    $decisionQuery->execute();
    $decisionResult = $decisionQuery->get_result();
    $decisions = [];
    while ($row = $decisionResult->fetch_assoc()) {
        $decisionId = (int) $row['accreditation_decision_id'];
        $creditsQuery = $db->prepare(
            'SELECT course_code, course_title, approved_credits
             FROM accredited_credits
             WHERE accreditation_decision_id = ?
             ORDER BY course_code, accredited_credit_id'
        );
        $creditsQuery->bind_param('i', $decisionId);
        $creditsQuery->execute();
        $creditsResult = $creditsQuery->get_result();
        $credits = [];
        while ($credit = $creditsResult->fetch_assoc()) {
            $credits[] = [
                'course_code' => (string) $credit['course_code'],
                'course_title' => (string) $credit['course_title'],
                'approved_credits' => (string) $credit['approved_credits'],
            ];
        }
        $creditsQuery->close();

        $decisions[] = [
            'accreditation_decision_id' => $decisionId,
            'decision' => (string) $row['decision'],
            'rationale' => (string) $row['rationale'],
            'decided_at' => (string) $row['decided_at'],
            'supersedes_decision_id' => $row['supersedes_decision_id'] === null ? null : (int) $row['supersedes_decision_id'],
            'is_current' => (int) $row['is_current'] === 1,
            'approved_credits' => $credits,
            'decided_by' => $isStaff ? (int) $row['decided_by'] : null,
        ];
    }
    $decisionQuery->close();

    $applicationStatus = (string) $application['application_status'];
    $canDecide = $isApprover && (
        ($currentDecisionId === null && $applicationStatus === 'awaiting_decision') ||
        ($currentDecisionId !== null && in_array($applicationStatus, ['awaiting_decision', 'approved', 'rejected', 'needs_correction'], true))
    );

    echo json_encode([
        'ok' => true,
        'application' => [
            'application_id' => (int) $application['application_id'],
            'application_number' => (string) $application['application_number'],
            'application_status' => $applicationStatus,
            'program' => [
                'code' => (string) $application['program_code'],
                'name' => (string) $application['program_name'],
            ],
        ],
        'current_decision_id' => $currentDecisionId,
        'can_decide' => $canDecide,
        'decisions' => $decisions,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (AuthenticationRequiredException $error) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'authentication_required']);
} catch (AuthorizationDeniedException $error) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'access_denied']);
} catch (Throwable $error) {
    error_log('EquiPath accreditation decision read failure: ' . get_class($error));
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'service_unavailable']);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
