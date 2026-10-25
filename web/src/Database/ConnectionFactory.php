<?php
declare(strict_types=1);

/**
 * Builds a MySQL connection from process environment variables.
 * Never commit credentials; configure them in the hosting environment.
 */
final class ConnectionFactory
{
    public static function createFromEnvironment(): mysqli
    {
        $host = self::requiredEnvironment('DB_HOST');
        $user = self::requiredEnvironment('DB_USER');
        $password = getenv('DB_PASSWORD');
        $database = self::requiredEnvironment('DB_NAME');
        $portValue = getenv('DB_PORT') ?: '3306';

        if (!ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
            throw new RuntimeException('Database configuration is invalid.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli($host, $user, $password === false ? '' : $password, $database, (int) $portValue);
        $db->set_charset('utf8mb4');

        return $db;
    }

    private static function requiredEnvironment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException('Required database configuration is missing.');
        }

        return trim($value);
    }
}
