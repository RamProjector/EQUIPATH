<?php
declare(strict_types=1);

/**
 * Server-side authorization primitives.
 *
 * Role membership is loaded from the database for each authorization decision;
 * roles are never accepted from request payloads or trusted from session data.
 * Callers must pass an explicit allow-list for staff access to each resource.
 */
final class AccessControl
{
    public function requireAuthenticated(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException('Start the session before checking authentication.');
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId) || $userId < 1) {
            throw new AuthenticationRequiredException('Authentication is required.');
        }

        return $userId;
    }

    /**
     * @param list<string> $allowedRoleKeys
     * @return list<string> Current database-backed role keys for the signed-in user.
     */
    public function requireAnyRole(mysqli $db, array $allowedRoleKeys): array
    {
        $userId = $this->requireAuthenticated();
        $allowedRoleKeys = $this->validateRoleKeys($allowedRoleKeys);
        if ($allowedRoleKeys === []) {
            throw new AuthorizationDeniedException('No roles are allowed for this action.');
        }

        $statement = $db->prepare(
            "SELECT r.role_key
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.user_id
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE u.user_id = ? AND u.account_status = 'active'
             ORDER BY r.role_key"
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();

        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $role = (string) $row['role_key'];
            if (in_array($role, $allowedRoleKeys, true)) {
                $roles[] = $role;
            }
        }
        $statement->close();

        if ($roles === []) {
            throw new AuthorizationDeniedException('The account is not permitted to perform this action.');
        }

        return array_values(array_unique($roles));
    }

    /**
     * Check access to one application. Applicants may access only their own
     * application; evaluators only applications assigned to them; other staff
     * must be explicitly included in $allowedStaffRoles. Administrator access
     * is not implicitly granted.
     *
     * @param list<string> $allowedStaffRoles Explicit staff role allow-list.
     */
    public function requireApplicationAccess(
        mysqli $db,
        int $applicationId,
        array $allowedStaffRoles = []
    ): void {
        $userId = $this->requireAuthenticated();
        if ($applicationId < 1) {
            throw new InvalidArgumentException('A valid application ID is required.');
        }
        $allowedStaffRoles = $this->validateRoleKeys($allowedStaffRoles);

        $statement = $db->prepare(
            'SELECT applicant_user_id FROM applications WHERE application_id = ? LIMIT 1'
        );
        $statement->bind_param('i', $applicationId);
        $statement->execute();
        $application = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!is_array($application)) {
            throw new AuthorizationDeniedException('Application access is denied.');
        }

        if ((int) $application['applicant_user_id'] === $userId) {
            $this->requireActiveAccount($db, $userId);
            return;
        }

        // An evaluator must have an explicit, non-cancelled assignment.
        $assignment = $db->prepare(
            "SELECT 1 FROM evaluator_assignments
             WHERE application_id = ? AND evaluator_user_id = ?
               AND assignment_status IN ('assigned', 'in_progress', 'submitted')
             LIMIT 1"
        );
        $assignment->bind_param('ii', $applicationId, $userId);
        $assignment->execute();
        $isAssignedEvaluator = $assignment->get_result()->num_rows > 0;
        $assignment->close();

        if ($isAssignedEvaluator) {
            $this->requireRoleExistsForActiveAccount($db, $userId, 'evaluator');
            return;
        }

        if ($allowedStaffRoles !== []) {
            $this->requireAnyRole($db, $allowedStaffRoles);
            return;
        }

        throw new AuthorizationDeniedException('Application access is denied.');
    }

    private function requireActiveAccount(mysqli $db, int $userId): void
    {
        $statement = $db->prepare("SELECT 1 FROM users WHERE user_id = ? AND account_status = 'active' LIMIT 1");
        $statement->bind_param('i', $userId);
        $statement->execute();
        $active = $statement->get_result()->num_rows === 1;
        $statement->close();

        if (!$active) {
            throw new AuthenticationRequiredException('The account is not active.');
        }
    }

    private function requireRoleExistsForActiveAccount(mysqli $db, int $userId, string $roleKey): void
    {
        $statement = $db->prepare(
            "SELECT 1 FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.user_id
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE u.user_id = ? AND u.account_status = 'active' AND r.role_key = ?
             LIMIT 1"
        );
        $statement->bind_param('is', $userId, $roleKey);
        $statement->execute();
        $authorized = $statement->get_result()->num_rows === 1;
        $statement->close();

        if (!$authorized) {
            throw new AuthorizationDeniedException('Application access is denied.');
        }
    }

    /** @param list<string> $roleKeys @return list<string> */
    private function validateRoleKeys(array $roleKeys): array
    {
        $validated = [];
        foreach ($roleKeys as $roleKey) {
            if (!is_string($roleKey) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $roleKey)) {
                throw new InvalidArgumentException('Role allow-list contains an invalid role key.');
            }
            $validated[] = $roleKey;
        }

        return array_values(array_unique($validated));
    }
}

final class AuthenticationRequiredException extends RuntimeException
{
}

final class AuthorizationDeniedException extends RuntimeException
{
}
