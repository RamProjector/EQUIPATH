<?php
declare(strict_types=1);

/**
 * Creates a new immutable rubric version. Active rubric changes are blocked
 * while evaluators have unfinished assignments for the program.
 */
final class RubricConfigurationService
{
    /**
     * @param list<array{criterion_code:string,criterion_name:string,description?:string|null,max_score:int|float|string,weight:int|float|string,sort_order?:int}> $criteria
     * @return array{rubric_id:int,program_id:int,rubric_name:string,version_number:int,criterion_count:int}
     */
    public function createVersion(mysqli $db, int $coordinatorUserId, int $programId, string $rubricName, array $criteria): array
    {
        if ($coordinatorUserId < 1 || $programId < 1) {
            throw new InvalidArgumentException('Positive coordinator and program identifiers are required.');
        }
        $rubricName = trim($rubricName);
        if ($rubricName === '' || strlen($rubricName) > 160 || str_contains($rubricName, "\0")) {
            throw new InvalidArgumentException('Rubric name must contain 1 to 160 bytes.');
        }
        if ($criteria === [] || count($criteria) > 100 || !array_is_list($criteria)) {
            throw new InvalidArgumentException('A rubric must contain between 1 and 100 criteria.');
        }

        $normalized = [];
        $codes = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion) ||
                !isset($criterion['criterion_code'], $criterion['criterion_name'], $criterion['max_score'], $criterion['weight']) ||
                !is_string($criterion['criterion_code']) ||
                !is_string($criterion['criterion_name']) ||
                (!is_string($criterion['description'] ?? '') && ($criterion['description'] ?? null) !== null) ||
                (isset($criterion['sort_order']) && !is_int($criterion['sort_order']))) {
                throw new InvalidArgumentException('A rubric criterion has invalid fields.');
            }

            $code = strtoupper(trim($criterion['criterion_code']));
            $name = trim($criterion['criterion_name']);
            $description = trim((string) ($criterion['description'] ?? ''));
            $maxScoreText = (string) $criterion['max_score'];
            $weightText = (string) $criterion['weight'];
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,39}$/D', $code) ||
                $name === '' || strlen($name) > 180 ||
                strlen($description) > 4000 || str_contains($description, "\0") ||
                !preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', $maxScoreText) ||
                !preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $weightText) ||
                (float) $maxScoreText <= 0 || (float) $weightText <= 0) {
                throw new InvalidArgumentException('A criterion has invalid code, name, description, maximum score or weight.');
            }
            $codeKey = strtoupper($code);
            if (isset($codes[$codeKey])) {
                throw new InvalidArgumentException('Criterion codes must be unique within a rubric.');
            }
            $codes[$codeKey] = true;
            $sortOrder = $criterion['sort_order'] ?? count($normalized);
            if ($sortOrder < 0 || $sortOrder > 10000) {
                throw new InvalidArgumentException('Criterion sort order is outside the supported range.');
            }
            $normalized[] = [
                'criterion_code' => $code,
                'criterion_name' => $name,
                'description' => $description === '' ? null : $description,
                'max_score' => $maxScoreText,
                'weight' => $weightText,
                'sort_order' => $sortOrder,
            ];
        }

        $db->begin_transaction();
        try {
            $program = $db->prepare(
                'SELECT program_id, is_active FROM academic_programs WHERE program_id = ? FOR UPDATE'
            );
            $program->bind_param('i', $programId);
            $program->execute();
            $programRow = $program->get_result()->fetch_assoc();
            $program->close();
            if (!is_array($programRow) || (int) $programRow['is_active'] !== 1) {
                throw new DomainException('Rubrics can only be configured for an active program.');
            }

            $activeRubrics = $db->prepare(
                'SELECT rubric_id FROM evaluation_rubrics
                 WHERE program_id = ? AND is_active = TRUE
                 ORDER BY rubric_id FOR UPDATE'
            );
            $activeRubrics->bind_param('i', $programId);
            $activeRubrics->execute();
            $activeResult = $activeRubrics->get_result();
            $activeIds = [];
            while ($row = $activeResult->fetch_assoc()) $activeIds[] = (int) $row['rubric_id'];
            $activeRubrics->close();
            if (count($activeIds) > 1) {
                throw new DomainException('Multiple active rubrics exist for this program; resolve the configuration before creating a new version.');
            }

            // Lock eligible/under-evaluation application rows so a concurrent
            // evaluator assignment cannot start under the old rubric between
            // the safety check and version activation.
            $evaluationStates = $db->prepare(
                "SELECT application_id, application_status
                 FROM applications
                 WHERE program_id = ? AND application_status IN ('eligible', 'under_evaluation')
                 FOR UPDATE"
            );
            $evaluationStates->bind_param('i', $programId);
            $evaluationStates->execute();
            $evaluationStateResult = $evaluationStates->get_result();
            $unfinishedCount = 0;
            while ($stateRow = $evaluationStateResult->fetch_assoc()) {
                if ((string) $stateRow['application_status'] === 'under_evaluation') {
                    $unfinishedCount++;
                }
            }
            $evaluationStates->close();
            if ($unfinishedCount > 0) {
                throw new DomainException('An active rubric cannot be replaced while any application for the program is under evaluation.');
            }

            $versionQuery = $db->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
                 FROM evaluation_rubrics WHERE program_id = ?'
            );
            $versionQuery->bind_param('i', $programId);
            $versionQuery->execute();
            $versionNumber = (int) $versionQuery->get_result()->fetch_assoc()['next_version'];
            $versionQuery->close();

            if ($activeIds !== []) {
                $deactivate = $db->prepare('UPDATE evaluation_rubrics SET is_active = FALSE WHERE rubric_id = ?');
                foreach ($activeIds as $activeId) {
                    $deactivate->bind_param('i', $activeId);
                    $deactivate->execute();
                }
                $deactivate->close();
            }

            $insertRubric = $db->prepare(
                'INSERT INTO evaluation_rubrics (program_id, rubric_name, version_number, is_active)
                 VALUES (?, ?, ?, TRUE)'
            );
            $insertRubric->bind_param('isi', $programId, $rubricName, $versionNumber);
            $insertRubric->execute();
            $rubricId = (int) $db->insert_id;
            $insertRubric->close();

            $insertCriterion = $db->prepare(
                'INSERT INTO rubric_criteria
                    (rubric_id, criterion_code, criterion_name, description, max_score, weight, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($normalized as $criterion) {
                $code = $criterion['criterion_code'];
                $name = $criterion['criterion_name'];
                $description = $criterion['description'];
                $maxScore = $criterion['max_score'];
                $weight = $criterion['weight'];
                $sortOrder = $criterion['sort_order'];
                $insertCriterion->bind_param('isssssi', $rubricId, $code, $name, $description, $maxScore, $weight, $sortOrder);
                $insertCriterion->execute();
            }
            $insertCriterion->close();

            $actionKey = 'evaluation_rubric.version_created';
            $entityType = 'evaluation_rubric';
            $auditOutcome = 'success';
            $metadata = json_encode([
                'program_id' => $programId,
                'rubric_name' => $rubricName,
                'version_number' => $versionNumber,
                'criterion_count' => count($normalized),
                'previous_active_rubric_id' => $activeIds[0] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $coordinatorUserId, $actionKey, $entityType, $rubricId, $auditOutcome, $metadata);
            $audit->execute();
            $audit->close();

            $db->commit();
            return [
                'rubric_id' => $rubricId,
                'program_id' => $programId,
                'rubric_name' => $rubricName,
                'version_number' => $versionNumber,
                'criterion_count' => count($normalized),
            ];
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}
