<?php
declare(strict_types=1);

/** Aggregate-only reporting. No applicant names, email addresses or document metadata are returned. */
final class ReportingService
{
    /** @return array{from:string,to:string} */
    public function normalizeDateRange(?string $from, ?string $to): array
    {
        $today = new DateTimeImmutable('today');
        $defaultTo = $today->format('Y-m-d');
        $defaultFrom = $today->modify('-89 days')->format('Y-m-d');
        $from = $from ?? $defaultFrom;
        $to = $to ?? $defaultTo;
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        $fromErrors = DateTimeImmutable::getLastErrors();
        $toErrors = DateTimeImmutable::getLastErrors();
        if (!$fromDate || !$toDate ||
            ($fromErrors !== false && ($fromErrors['warning_count'] > 0 || $fromErrors['error_count'] > 0)) ||
            ($toErrors !== false && ($toErrors['warning_count'] > 0 || $toErrors['error_count'] > 0)) ||
            $fromDate->format('Y-m-d') !== $from || $toDate->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Report dates must use YYYY-MM-DD.');
        }
        if ($fromDate > $toDate || $fromDate->diff($toDate)->days > 365) {
            throw new InvalidArgumentException('Report range must be ordered and no longer than 366 calendar days.');
        }
        return ['from' => $from, 'to' => $to];
    }

    /** @return array<string,mixed> */
    public function build(mysqli $db, string $from, string $to): array
    {
        $totals = [
            'applications_created' => $this->scalar($db,
                'SELECT COUNT(*) AS total FROM applications WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)', $from, $to),
            'applications_submitted' => $this->scalar($db,
                'SELECT COUNT(*) AS total FROM applications WHERE submitted_at >= ? AND submitted_at < DATE_ADD(?, INTERVAL 1 DAY)', $from, $to),
            'applications_currently_open' => $this->scalar($db,
                "SELECT COUNT(*) AS total FROM applications WHERE application_status NOT IN ('approved', 'rejected', 'ineligible', 'withdrawn')"),
        ];

        return [
            'range' => ['from' => $from, 'to' => $to],
            'totals' => $totals,
            'applications_by_status' => $this->rows($db,
                'SELECT application_status AS label, COUNT(*) AS total
                 FROM applications WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
                 GROUP BY application_status ORDER BY total DESC, label', $from, $to),
            'applications_by_program' => $this->rows($db,
                'SELECT p.program_code AS code, p.program_name AS name, COUNT(*) AS total
                 FROM applications a INNER JOIN academic_programs p ON p.program_id = a.program_id
                 WHERE a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)
                 GROUP BY p.program_id, p.program_code, p.program_name
                 ORDER BY total DESC, p.program_name LIMIT 100', $from, $to),
            'submissions_by_month' => $this->rows($db,
                "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS label, COUNT(*) AS total
                 FROM applications WHERE submitted_at >= ? AND submitted_at < DATE_ADD(?, INTERVAL 1 DAY)
                 GROUP BY DATE_FORMAT(submitted_at, '%Y-%m') ORDER BY label", $from, $to),
            'screening_outcomes' => $this->rows($db,
                'SELECT outcome AS label, COUNT(*) AS total
                 FROM screening_reviews WHERE reviewed_at >= ? AND reviewed_at < DATE_ADD(?, INTERVAL 1 DAY)
                   AND outcome <> \'pending\'
                 GROUP BY outcome ORDER BY total DESC, label', $from, $to),
            'evaluator_assignments' => $this->rows($db,
                'SELECT assignment_status AS label, COUNT(*) AS total
                 FROM evaluator_assignments WHERE assigned_at >= ? AND assigned_at < DATE_ADD(?, INTERVAL 1 DAY)
                 GROUP BY assignment_status ORDER BY total DESC, label', $from, $to),
            'assessment_events' => $this->rows($db,
                'SELECT event_status AS label, COUNT(*) AS total
                 FROM assessment_events WHERE starts_at >= ? AND starts_at < DATE_ADD(?, INTERVAL 1 DAY)
                 GROUP BY event_status ORDER BY total DESC, label', $from, $to),
            'accreditation_decisions' => $this->rows($db,
                'SELECT decision AS label, COUNT(*) AS total
                 FROM accreditation_decisions WHERE decided_at >= ? AND decided_at < DATE_ADD(?, INTERVAL 1 DAY)
                 GROUP BY decision ORDER BY total DESC, label', $from, $to),
            'approved_credits' => $this->approvedCredits($db, $from, $to),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rows(mysqli $db, string $sql, string $from, string $to): array
    {
        $statement = $db->prepare($sql);
        $statement->bind_param('ss', $from, $to);
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (array_key_exists('total', $row)) {
                $row['total'] = (int) $row['total'];
            }
            $rows[] = $row;
        }
        $statement->close();
        return $rows;
    }

    private function scalar(mysqli $db, string $sql, ?string $from = null, ?string $to = null): int
    {
        $statement = $db->prepare($sql);
        if ($from !== null && $to !== null) $statement->bind_param('ss', $from, $to);
        $statement->execute();
        $value = (int) ($statement->get_result()->fetch_assoc()['total'] ?? 0);
        $statement->close();
        return $value;
    }

    /** @return array{approved_decisions:int,course_count:int,total_credits:string} */
    private function approvedCredits(mysqli $db, string $from, string $to): array
    {
        $statement = $db->prepare(
            "SELECT COUNT(DISTINCT d.accreditation_decision_id) AS approved_decisions,
                    COUNT(c.accredited_credit_id) AS course_count,
                    COALESCE(SUM(c.approved_credits), 0) AS total_credits
             FROM accreditation_decisions d
             LEFT JOIN accredited_credits c ON c.accreditation_decision_id = d.accreditation_decision_id
             WHERE d.decision = 'approved'
               AND d.decided_at >= ? AND d.decided_at < DATE_ADD(?, INTERVAL 1 DAY)"
        );
        $statement->bind_param('ss', $from, $to);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc() ?: [];
        $statement->close();
        return [
            'approved_decisions' => (int) ($row['approved_decisions'] ?? 0),
            'course_count' => (int) ($row['course_count'] ?? 0),
            'total_credits' => number_format((float) ($row['total_credits'] ?? 0), 2, '.', ''),
        ];
    }

    /** @param array<string,mixed> $report */
    public function toCsv(array $report): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to prepare report export.');
        fputcsv($stream, ['EquiPath aggregate report']);
        fputcsv($stream, ['From', (string) $report['range']['from']]);
        fputcsv($stream, ['To', (string) $report['range']['to']]);
        fputcsv($stream, []);
        foreach ($report['totals'] as $label => $value) {
            fputcsv($stream, [$this->csvCell((string) $label), $this->csvCell((string) $value)]);
        }
        fputcsv($stream, []);
        foreach ([
            'applications_by_status' => 'Applications by status',
            'applications_by_program' => 'Applications by program',
            'submissions_by_month' => 'Submissions by month',
            'screening_outcomes' => 'Screening outcomes',
            'evaluator_assignments' => 'Evaluator assignments',
            'assessment_events' => 'Assessment events',
            'accreditation_decisions' => 'Accreditation decisions',
        ] as $key => $title) {
            fputcsv($stream, []);
            fputcsv($stream, [$title]);
            $rows = $report[$key] ?? [];
            if ($rows === []) {
                fputcsv($stream, ['No data']);
                continue;
            }
            fputcsv($stream, array_map(fn($field) => $this->csvCell((string) $field), array_keys($rows[0])));
            foreach ($rows as $row) {
                fputcsv($stream, array_map(fn($value) => $this->csvCell((string) ($value ?? '')), array_values($row)));
            }
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Approved credits summary']);
        fputcsv($stream, ['Approved decisions', (string) $report['approved_credits']['approved_decisions']]);
        fputcsv($stream, ['Courses', (string) $report['approved_credits']['course_count']]);
        fputcsv($stream, ['Total approved credits', (string) $report['approved_credits']['total_credits']]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return is_string($csv) ? $csv : '';
    }

    private function csvCell(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && preg_match('/^[=+\-@]/', $trimmed)) {
            return "'" . $value;
        }
        return $value;
    }
}
