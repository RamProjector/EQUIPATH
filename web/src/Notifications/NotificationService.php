<?php
declare(strict_types=1);

/**
 * Provides recipient-scoped notification access and idempotent read updates.
 *
 * Callers must authenticate the current user and enforce an active, assigned
 * role before invoking this service.
 */
final class NotificationService
{
    /**
     * @return list<array{notification_id:int,notification_type:string,title:string,message:string,related_application_id:?int,read_at:?string,created_at:string}>
     */
    public function listForRecipient(mysqli $db, int $recipientUserId, int $limit = 100): array
    {
        if ($recipientUserId < 1) {
            throw new InvalidArgumentException('A positive recipient user ID is required.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Notification limit must be between 1 and 100.');
        }

        $statement = $db->prepare(
            'SELECT notification_id, notification_type, title, message,
                    related_application_id, read_at, created_at
             FROM notifications
             WHERE recipient_user_id = ?
             ORDER BY created_at DESC, notification_id DESC
             LIMIT ?'
        );
        $statement->bind_param('ii', $recipientUserId, $limit);
        $statement->execute();
        $result = $statement->get_result();
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'notification_id' => (int) $row['notification_id'],
                'notification_type' => (string) $row['notification_type'],
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'related_application_id' => $row['related_application_id'] === null
                    ? null
                    : (int) $row['related_application_id'],
                'read_at' => $row['read_at'] === null ? null : (string) $row['read_at'],
                'created_at' => (string) $row['created_at'],
            ];
        }
        $statement->close();

        return $notifications;
    }

    /**
     * Marks a notification as read only for its recipient.
     *
     * Repeating the operation for an already-read notification is idempotent.
     *
     * @return array{notification_id:int,read_at:string,already_read:bool}
     */
    public function markRead(mysqli $db, int $recipientUserId, int $notificationId): array
    {
        if ($recipientUserId < 1 || $notificationId < 1) {
            throw new InvalidArgumentException('Positive recipient and notification IDs are required.');
        }

        $db->begin_transaction();
        try {
            $find = $db->prepare(
                'SELECT read_at FROM notifications
                 WHERE notification_id = ? AND recipient_user_id = ?
                 FOR UPDATE'
            );
            $find->bind_param('ii', $notificationId, $recipientUserId);
            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            $find->close();

            if (!is_array($row)) {
                // Do not disclose whether another user owns this notification.
                throw new DomainException('The notification was not found for this recipient.');
            }

            $alreadyRead = $row['read_at'] !== null;
            if (!$alreadyRead) {
                $update = $db->prepare(
                    'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
                     WHERE notification_id = ? AND recipient_user_id = ? AND read_at IS NULL'
                );
                $update->bind_param('ii', $notificationId, $recipientUserId);
                $update->execute();
                if ($update->affected_rows !== 1) {
                    $update->close();
                    throw new RuntimeException('The notification read state could not be updated.');
                }
                $update->close();

                $actionKey = 'notification.read';
                $entityType = 'notification';
                $auditOutcome = 'success';
                $metadata = json_encode(['read' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $audit = $db->prepare(
                    'INSERT INTO audit_logs
                        (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $audit->bind_param(
                    'ississ',
                    $recipientUserId,
                    $actionKey,
                    $entityType,
                    $notificationId,
                    $auditOutcome,
                    $metadata
                );
                $audit->execute();
                $audit->close();
            }

            $readAt = $row['read_at'] === null
                ? date('Y-m-d H:i:s')
                : (string) $row['read_at'];
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }

        return [
            'notification_id' => $notificationId,
            'read_at' => $readAt,
            'already_read' => $alreadyRead,
        ];
    }
}
