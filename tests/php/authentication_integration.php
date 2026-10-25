<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once dirname(__DIR__, 2) . '/web/src/Security/Authenticator.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'equipath_eteeap';
$db = new mysqli($host, $user, $password, $database);
$db->set_charset('utf8mb4');

$suffix = bin2hex(random_bytes(6));
$testPassword = 'ci-only-password-' . $suffix;
$hash = password_hash($testPassword, PASSWORD_DEFAULT);
$accounts = [
    ['auth-active-' . $suffix . '@example.invalid', 'active'],
    ['auth-pending-' . $suffix . '@example.invalid', 'pending'],
    ['auth-disabled-' . $suffix . '@example.invalid', 'disabled'],
];
$ids = [];
try {
    $insert = $db->prepare("INSERT INTO users (email, password_hash, given_name, family_name, account_status) VALUES (?, ?, 'Auth', 'Test', ?)");
    foreach ($accounts as [$email, $status]) {
        $insert->bind_param('sss', $email, $hash, $status);
        $insert->execute();
        $ids[] = (int) $db->insert_id;
    }
    $insert->close();

    $auth = new Authenticator();
    $valid = $auth->authenticate($db, strtoupper($accounts[0][0]), $testPassword);
    if (!is_array($valid) || $valid['user_id'] !== $ids[0] || array_key_exists('password_hash', $valid)) {
        throw new RuntimeException('Active account with valid credentials was not safely authenticated.');
    }
    if ($auth->authenticate($db, $accounts[0][0], 'incorrect-password') !== null) {
        throw new RuntimeException('Incorrect password was accepted.');
    }
    if ($auth->authenticate($db, 'unknown-' . $suffix . '@example.invalid', $testPassword) !== null) {
        throw new RuntimeException('Unknown email was accepted.');
    }
    if ($auth->authenticate($db, $accounts[1][0], $testPassword) !== null) {
        throw new RuntimeException('Pending account was accepted.');
    }
    if ($auth->authenticate($db, $accounts[2][0], $testPassword) !== null) {
        throw new RuntimeException('Disabled account was accepted.');
    }
    if ($auth->authenticate($db, '   ', $testPassword) !== null ||
        $auth->authenticate($db, $accounts[0][0], '') !== null) {
        throw new RuntimeException('Empty credentials were accepted.');
    }

    $check = $db->prepare('SELECT last_login_at FROM users WHERE user_id = ?');
    $check->bind_param('i', $ids[0]);
    $check->execute();
    $lastLogin = $check->get_result()->fetch_assoc()['last_login_at'] ?? null;
    $check->close();
    if ($lastLogin === null) {
        throw new RuntimeException('Successful login did not update last_login_at.');
    }

    fwrite(STDOUT, "PASS: authentication integration checks (active, bad password, unknown, pending, disabled, empty input, safe identity).\n");
} finally {
    if ($ids !== []) {
        $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
        foreach ($ids as $id) {
            $delete->bind_param('i', $id);
            $delete->execute();
        }
        $delete->close();
    }
    $db->close();
}
