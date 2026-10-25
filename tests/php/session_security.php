<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Security/SessionManager.php';

$manager = new SessionManager();

try {
    $manager->start();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session did not become active.');
    }

    $identity = [
        'user_id' => 42,
        'email' => 'session-test@example.invalid',
        'given_name' => 'Session',
        'family_name' => 'Test',
    ];
    $manager->establishIdentity($identity);

    if (($_SESSION['user_id'] ?? null) !== 42 ||
        isset($_SESSION['password_hash']) ||
        !isset($_SESSION['authenticated_at'])) {
        throw new RuntimeException('Session identity is missing or contains unsafe fields.');
    }

    $token = $manager->csrfToken();
    if (strlen($token) !== 64 || !$manager->isValidCsrfToken($token)) {
        throw new RuntimeException('Generated CSRF token was invalid.');
    }
    if ($manager->isValidCsrfToken('wrong-token') || $manager->isValidCsrfToken(null)) {
        throw new RuntimeException('Invalid CSRF token was accepted.');
    }

    $sameToken = $manager->csrfToken();
    if (!hash_equals($token, $sameToken)) {
        throw new RuntimeException('CSRF token changed unexpectedly during the same session.');
    }

    $manager->destroy();
    if (session_status() !== PHP_SESSION_NONE) {
        throw new RuntimeException('Session was not destroyed.');
    }

    fwrite(STDOUT, "PASS: session security checks (session start, identity minimization, CSRF token validation, logout destruction).\n");
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    fwrite(STDERR, 'FAIL: session security checks — ' . $error->getMessage() . "\n");
    exit(1);
}
