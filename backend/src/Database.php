<?php

declare(strict_types=1);

namespace StudentVoice;

use PDO;

/** The one database (DB_NAME): students, polls, votes and admins. */
final class Database
{
    private static ?PDO $db = null;

    public static function connect(string $dbName): PDO
    {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Config::get('DB_HOST', '127.0.0.1'),
                (int) Config::get('DB_PORT', 3306),
                $dbName
            ),
            (string) Config::get('DB_USER', 'root'),
            (string) Config::get('DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '" . (new \DateTime())->format('P') . "'");
        return $pdo;
    }

    public static function name(): string
    {
        return (string) Config::get('DB_NAME', 'studentvoice');
    }

    public static function get(): PDO
    {
        return self::$db ??= self::connect(self::name());
    }

    /** Runs $work inside a transaction; all of it is saved, or none */
    public static function transaction(callable $work): mixed
    {
        $db = self::get();
        $db->beginTransaction();
        try {
            $result = $work($db);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
