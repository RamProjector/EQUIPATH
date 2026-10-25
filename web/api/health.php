<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

http_response_code(200);

echo json_encode([
    'ok' => true,
    'service' => 'equipath-api',
    'status' => 'starter',
    'database' => 'not_checked',
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
