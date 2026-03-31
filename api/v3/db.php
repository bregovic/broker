<?php
namespace Broker\V3;

use PDO;
use Exception;

class DB {
    private static $pdo = null;

    public static function connect() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        require_once __DIR__ . '/../config.php';
        self::$pdo = get_pdo();
        return self::$pdo;
    }

    public static function query($sql, $params = []) {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
