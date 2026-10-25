<?php
declare(strict_types=1);

/**
 * Secure session primitives for future HTTP authentication endpoints.
 *
 * This class does not authenticate users or authorize requests. Callers must
 * establish identity only after successful authentication and enforce roles
 * and resource ownership on every protected endpoint.
 */
final class SessionManager
{
    private const SESSION_NAME = 'EQUIPATH_SESSION';
    private const CSRF_KEY = '_csrf_token';

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException('Session must be started before response output.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(self::SESSION_NAME);

        $isHttps = isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== '' &&
            strtolower((string) $_SERVER['HTTPS']) !== 'off';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Unable to start session.');
        }
    }

    /**
     * Rotate the session identifier after successful credential verification.
     * The supplied identity must be the safe return value from Authenticator.
     *
     * @param array{user_id:int,email:string,given_name:string,family_name:string} $identity
     */
    public function establishIdentity(array $identity): void
    {
        $this->requireActiveSession();

        if (!isset($identity['user_id'], $identity['email'], $identity['given_name'], $identity['family_name']) ||
            !is_int($identity['user_id']) || $identity['user_id'] < 1) {
            throw new InvalidArgumentException('A validated account identity is required.');
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate session identifier.');
        }

        $_SESSION = [
            'user_id' => $identity['user_id'],
            'email' => $identity['email'],
            'given_name' => $identity['given_name'],
            'family_name' => $identity['family_name'],
            'authenticated_at' => time(),
        ];

        unset($_SESSION[self::CSRF_KEY]);
    }

    public function csrfToken(): string
    {
        $this->requireActiveSession();

        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public function isValidCsrfToken(?string $candidate): bool
    {
        $this->requireActiveSession();

        return is_string($candidate) &&
            $candidate !== '' &&
            isset($_SESSION[self::CSRF_KEY]) &&
            is_string($_SESSION[self::CSRF_KEY]) &&
            hash_equals($_SESSION[self::CSRF_KEY], $candidate);
    }

    public function destroy(): void
    {
        $this->requireActiveSession();
        $_SESSION = [];

        $params = session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?: 'Lax',
            ]);
        }

        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy session.');
        }
    }

    private function requireActiveSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('Start the session before using session security methods.');
        }
    }
}
