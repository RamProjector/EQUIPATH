<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Integration/RegistrarImportPreview.php';

function registrarPreviewAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = 1_800_000_000;
$token = bin2hex(random_bytes(32));
$pending = [
    'token_hash' => hash('sha256', $token),
    'registrar_user_id' => 73,
    'source_system' => 'COLLEGE_X',
    'rows' => [['external_record_id' => 'EXT-1']],
    'created_at' => $now,
];

registrarPreviewAssert(
    RegistrarImportPreview::isValid($token, $pending, 73, $now),
    'A valid token for the same registrar and current time should be accepted.'
);
registrarPreviewAssert(
    RegistrarImportPreview::isValid($token, array_merge($pending, ['created_at' => $now - 900]), 73, $now),
    'A preview at the exact 15-minute expiry boundary should remain valid.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid($token, array_merge($pending, ['created_at' => $now - 901]), 73, $now),
    'An expired preview must be rejected.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid($token, array_merge($pending, ['created_at' => $now + 1]), 73, $now),
    'A preview timestamp in the future must be rejected.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid($token, $pending, 74, $now),
    'A preview must not be usable by a different authenticated user.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid(str_repeat('0', 64), $pending, 73, $now),
    'A token that does not match the stored hash must be rejected.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid('not-a-token', $pending, 73, $now),
    'A malformed token must be rejected.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid($token, null, 73, $now),
    'A missing pending preview (including a replay after consumption) must be rejected.'
);
registrarPreviewAssert(
    !RegistrarImportPreview::isValid($token, array_merge($pending, ['token_hash' => 'invalid']), 73, $now),
    'A malformed stored token hash must be rejected.'
);

fwrite(STDOUT, "PASS: registrar preview token lifecycle (validity, expiry boundary, stale/future timestamps, user binding, token mismatch, replay/missing state).\\n");
