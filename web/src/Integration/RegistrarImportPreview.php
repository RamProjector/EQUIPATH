<?php
declare(strict_types=1);

/**
 * Validates the session-bound, one-use token for a registrar import preview.
 *
 * The caller must already authenticate the current user and enforce the
 * registrar role. This class only validates preview-token binding and age.
 */
final class RegistrarImportPreview
{
    public const TTL_SECONDS = 900;

    /**
     * @param mixed $previewToken
     * @param mixed $pending
     */
    public static function isValid(mixed $previewToken, mixed $pending, int $registrarUserId, ?int $now = null): bool
    {
        $now ??= time();

        if (!is_string($previewToken) ||
            !preg_match('/^[a-f0-9]{64}$/D', $previewToken) ||
            !is_array($pending) ||
            !isset($pending['token_hash'], $pending['registrar_user_id'], $pending['source_system'], $pending['rows'], $pending['created_at']) ||
            !is_string($pending['token_hash']) ||
            !preg_match('/^[a-f0-9]{64}$/D', $pending['token_hash']) ||
            !is_int($pending['registrar_user_id']) ||
            $pending['registrar_user_id'] !== $registrarUserId ||
            !is_string($pending['source_system']) ||
            !is_array($pending['rows']) ||
            !is_int($pending['created_at']) ||
            $pending['created_at'] > $now ||
            $now - $pending['created_at'] > self::TTL_SECONDS) {
            return false;
        }

        return hash_equals($pending['token_hash'], hash('sha256', $previewToken));
    }
}
