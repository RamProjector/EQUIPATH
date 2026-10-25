<?php
declare(strict_types=1);

/**
 * Minimal database-backed credential verifier.
 *
 * This class does not create sessions, authorize actions, assign roles, or
 * implement throttling. Those must be handled by dedicated layers before use.
 */
final class Authenticator
{
    // A fixed bcrypt hash keeps the unknown-email path doing password-hash work.
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igin';

    /**
     * Return a safe user identity on success, otherwise null.
     * Password hashes are never included in the returned identity.
     *
     * @return array{user_id:int,email:string,given_name:string,family_name:string}|null
     */
    public function authenticate(mysqli $db, string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > 4096) {
            return null;
        }

        $statement = $db->prepare(
            'SELECT user_id, email, password_hash, given_name, family_name, account_status
             FROM users
             WHERE email = ?
             LIMIT 1'
        );
        $statement->bind_param('s', $email);
        $statement->execute();
        $result = $statement->get_result();
        $user = $result->fetch_assoc();
        $statement->close();

        if (!is_array($user)) {
            password_verify($password, self::DUMMY_HASH);
            return null;
        }

        $passwordIsValid = password_verify($password, (string) $user['password_hash']);
        if ($user['account_status'] !== 'active' || !$passwordIsValid) {
            return null;
        }

        $userId = (int) $user['user_id'];
        $update = $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?');
        $update->bind_param('i', $userId);
        $update->execute();
        $update->close();

        return [
            'user_id' => $userId,
            'email' => (string) $user['email'],
            'given_name' => (string) $user['given_name'],
            'family_name' => (string) $user['family_name'],
        ];
    }
}
