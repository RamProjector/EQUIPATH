<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourceProtectionPath = $root . '/web/src/.htaccess';

if (!is_file($sourceProtectionPath)) {
    fwrite(STDERR, "Missing Apache rule protecting web/src from direct HTTP access.\n");
    exit(1);
}

$rules = file_get_contents($sourceProtectionPath);
if (!is_string($rules) || !preg_match('/^\s*Require\s+all\s+denied\s*$/mi', $rules)) {
    fwrite(STDERR, "web/src/.htaccess must deny all direct HTTP access.\n");
    exit(1);
}

echo "Deployment security contract passed.\n";
