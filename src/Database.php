<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function connect(array $config): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['name'],
            $config['charset']
        );

        try {
            self::$instance = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
        } catch (PDOException $e) {
            // Never expose DB error details to output
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Service unavailable');
        }

        return self::$instance;
    }
}
