<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Security/LoginRateLimiter.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'equipath_eteeap';
$secret = getenv('AUTH_RATE_LIMIT_SECRET') ?: '';

if (strlen($secret) < 32) {
    fwrite(STDERR, "FAIL: AUTH_RATE_LIMIT_SECRET must be at least 32 characters for this test.\n");
    exit(1);
}

$db = null;
try {
    $db = new mysqli($host, $user, $password, $database);
    $limiter = new LoginRateLimiter($db);
    $email = 'rate-limit-' . bin2hex(random_bytes(8)) . '@example.invalid';
    $ip = '192.0.2.25';

    if ($limiter->isBlocked($email, $ip)) {
        throw new RuntimeException('New rate-limit subject unexpectedly blocked.');
    }

    for ($i = 0; $i < 8; $i++) {
        $limiter->recordFailure($email, $ip);
    }
    if (!$limiter->isBlocked($email, $ip)) {
        throw new RuntimeException('Subject was not blocked after the configured failure threshold.');
    }

    $limiter->clear($email, $ip);
    if ($limiter->isBlocked($email, $ip)) {
        throw new RuntimeException('Clearing a successful-login subject did not remove its block.');
    }

    fwrite(STDOUT, "PASS: login rate-limit integration checks (threshold, block, clear).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: login rate-limit integration checks — ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
