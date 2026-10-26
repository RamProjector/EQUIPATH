<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Notifications/NotificationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
$exitCode = 0;
$userIds = [];
$roleIdsCreated = [];

function notificationInsertId(mysqli $db): int
{
    return (int) $db->insert_id;
}

function notificationEnsureRole(mysqli $db, array &$createdRoleIds): int
{
    $roleKey = 'applicant';
    $find = $db->prepare('SELECT role_id FROM roles WHERE role_key = ? LIMIT 1');
    $find->bind_param('s', $roleKey);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if (is_array($row)) {
        return (int) $row['role_id'];
    }

    $label = 'Applicant';
    $insert = $db->prepare('INSERT INTO roles (role_key, display_name) VALUES (?, ?)');
    $insert->bind_param('ss', $roleKey, $label);
    $insert->execute();
    $roleId = notificationInsertId($db);
    $insert->close();
    $createdRoleIds[] = $roleId;
    return $roleId;
}

try {
    $db = new mysqli(
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_NAME') ?: 'equipath_eteeap',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $db->set_charset('utf8mb4');
    $suffix = bin2hex(random_bytes(8));
    $roleId = notificationEnsureRole($db, $roleIdsCreated);

    $insertUser = $db->prepare(
        "INSERT INTO users (email, password_hash, given_name, family_name, account_status)
         VALUES (?, ?, ?, ?, 'active')"
    );
    $passwordHash = password_hash('notification-test-password', PASSWORD_DEFAULT);
    foreach (['recipient', 'other'] as $kind) {
        $email = $kind . '-notification-' . $suffix . '@example.invalid';
        $givenName = ucfirst($kind);
        $familyName = 'NotificationTest';
        $insertUser->bind_param('ssss', $email, $passwordHash, $givenName, $familyName);
        $insertUser->execute();
        $userIds[$kind] = notificationInsertId($db);
    }
    $insertUser->close();

    foreach ($userIds as $userId) {
        $assign = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        $assign->bind_param('ii', $userId, $roleId);
        $assign->execute();
        $assign->close();
    }

    $insertNotification = $db->prepare(
        'INSERT INTO notifications (recipient_user_id, notification_type, title, message)
         VALUES (?, ?, ?, ?)'
    );
    $notificationType = 'test_notice';
    $title = 'Notification integration test';
    foreach ([
        'recipient' => 'First recipient-only notice',
        'recipient_second' => 'Second recipient-only notice',
        'other' => 'Notice for another user',
    ] as $kind => $message) {
        $recipientKey = $kind === 'recipient_second' ? 'recipient' : $kind;
        $recipientId = $userIds[$recipientKey];
        $insertNotification->bind_param('isss', $recipientId, $notificationType, $title, $message);
        $insertNotification->execute();
        if ($kind === 'recipient') {
            $firstNotificationId = notificationInsertId($db);
        } elseif ($kind === 'recipient_second') {
            $secondNotificationId = notificationInsertId($db);
        } else {
            $otherNotificationId = notificationInsertId($db);
        }
    }
    $insertNotification->close();

    $service = new NotificationService();
    $inbox = $service->listForRecipient($db, $userIds['recipient']);
    $inboxIds = array_column($inbox, 'notification_id');
    sort($inboxIds);
    $expectedIds = [$firstNotificationId, $secondNotificationId];
    sort($expectedIds);
    if ($inboxIds !== $expectedIds || count($inbox) !== 2) {
        throw new RuntimeException('Notification inbox returned another user’s notification or omitted an owned notification.');
    }

    $marked = $service->markRead($db, $userIds['recipient'], $firstNotificationId);
    if ($marked['notification_id'] !== $firstNotificationId ||
        $marked['already_read'] !== false ||
        $marked['read_at'] === '') {
        throw new RuntimeException('Mark-read did not return the expected first-read result.');
    }

    $stored = $db->prepare('SELECT read_at FROM notifications WHERE notification_id = ? AND recipient_user_id = ?');
    $stored->bind_param('ii', $firstNotificationId, $userIds['recipient']);
    $stored->execute();
    $storedReadAt = $stored->get_result()->fetch_assoc()['read_at'] ?? null;
    $stored->close();
    if (!is_string($storedReadAt) || $storedReadAt !== $marked['read_at']) {
        throw new RuntimeException('Mark-read did not persist and return the database read timestamp.');
    }

    $repeat = $service->markRead($db, $userIds['recipient'], $firstNotificationId);
    if ($repeat['already_read'] !== true || $repeat['read_at'] !== $marked['read_at']) {
        throw new RuntimeException('Mark-read was not idempotent for an already-read notification.');
    }

    $audit = $db->prepare(
        "SELECT COUNT(*) AS total FROM audit_logs
         WHERE actor_user_id = ? AND action_key = 'notification.read'
           AND entity_type = 'notification' AND entity_id = ? AND outcome = 'success'"
    );
    $audit->bind_param('ii', $userIds['recipient'], $firstNotificationId);
    $audit->execute();
    $auditCount = (int) $audit->get_result()->fetch_assoc()['total'];
    $audit->close();
    if ($auditCount !== 1) {
        throw new RuntimeException('First-read audit record was missing or duplicated.');
    }

    try {
        $service->markRead($db, $userIds['recipient'], $otherNotificationId);
        throw new RuntimeException('Recipient was able to mark another user’s notification as read.');
    } catch (DomainException) {
        // Expected: ownership mismatch is reported as not found.
    }

    $otherState = $db->prepare('SELECT read_at FROM notifications WHERE notification_id = ? AND recipient_user_id = ?');
    $otherState->bind_param('ii', $otherNotificationId, $userIds['other']);
    $otherState->execute();
    $otherReadAt = $otherState->get_result()->fetch_assoc()['read_at'] ?? null;
    $otherState->close();
    if ($otherReadAt !== null) {
        throw new RuntimeException('Unauthorized mark-read changed the other recipient’s notification.');
    }

    try {
        $service->listForRecipient($db, $userIds['recipient'], 101);
        throw new RuntimeException('Notification inbox accepted a limit above 100.');
    } catch (InvalidArgumentException) {
        // Expected: the inbox has a fixed upper bound.
    }

    fwrite(STDOUT, "PASS: notification service (recipient isolation, idempotent read state, audit event, invalid limits).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: notification integration — ' . $error->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($db instanceof mysqli) {
        foreach ($userIds as $userId) {
            $delete = $db->prepare('DELETE FROM audit_logs WHERE actor_user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM notifications WHERE recipient_user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $delete = $db->prepare('DELETE FROM users WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();
        }

        foreach ($roleIdsCreated as $roleId) {
            $delete = $db->prepare('DELETE FROM roles WHERE role_id = ?');
            $delete->bind_param('i', $roleId);
            $delete->execute();
            $delete->close();
        }
        $db->close();
    }
}

exit($exitCode);
