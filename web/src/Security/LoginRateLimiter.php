<?php
declare(strict_types=1);

/**
 * Database-backed fixed-window login throttling.
 * The caller must supply a normalized email and a trusted client IP address.
 * Configure trusted proxy handling at the web server; do not blindly trust
 * X-Forwarded-For supplied by arbitrary clients.
 */
final class LoginRateLimiter
{
    private const MAX_ATTEMPTS = 8;
    private const WINDOW_SECONDS = 900;
    private const BLOCK_SECONDS = 900;

    public function __construct(private readonly mysqli $db)
    {
    }

    public function isBlocked(string $normalizedEmail, string $clientIp): bool
    {
        $subjectHash = $this->subjectHash($normalizedEmail, $clientIp);
        $statement = $this->db->prepare(
            'SELECT blocked_until FROM authentication_rate_limits WHERE subject_hash = ? LIMIT 1'
        );
        $statement->bind_param('s', $subjectHash);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        return is_array($row) && $row['blocked_until'] !== null &&
            strtotime((string) $row['blocked_until']) > time();
    }

    public function recordFailure(string $normalizedEmail, string $clientIp): void
    {
        $subjectHash = $this->subjectHash($normalizedEmail, $clientIp);
        $this->db->begin_transaction();

        try {
            $statement = $this->db->prepare(
                'INSERT INTO authentication_rate_limits
                    (subject_hash, window_started_at, attempt_count, blocked_until)
                 VALUES (?, CURRENT_TIMESTAMP, 1, NULL)
                 ON DUPLICATE KEY UPDATE
                    attempt_count = IF(window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), 1, attempt_count + 1),
                    blocked_until = IF(
                        window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE),
                        NULL,
                        IF(attempt_count + 1 >= 8, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), blocked_until)
                    ),
                    window_started_at = IF(window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE), CURRENT_TIMESTAMP, window_started_at)'
            );
            $statement->bind_param('s', $subjectHash);
            $statement->execute();
            $statement->close();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function clear(string $normalizedEmail, string $clientIp): void
    {
        $subjectHash = $this->subjectHash($normalizedEmail, $clientIp);
        $statement = $this->db->prepare('DELETE FROM authentication_rate_limits WHERE subject_hash = ?');
        $statement->bind_param('s', $subjectHash);
        $statement->execute();
        $statement->close();
    }

    private function subjectHash(string $email, string $ip): string
    {
        $secret = getenv('AUTH_RATE_LIMIT_SECRET');
        if ($secret === false || strlen($secret) < 32) {
            throw new RuntimeException('Authentication rate-limit secret is not configured.');
        }

        return hash_hmac('sha256', strtolower(trim($email)) . '|' . $ip, $secret);
    }
}
