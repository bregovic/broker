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

        // 1. Zkusíme vytáhnout DATABASE_URL z env (Railway standard)
        $databaseUrl = getenv('DATABASE_URL');
        
        // 2. Pokud není v env, zkusíme tvůj internal URL (fallback pro tento session)
        if (!$databaseUrl) {
            $databaseUrl = "postgresql://postgres:uDgeRzClcqLegFZbdIwDwCXqJqaDcnNn@postgres-j0eo.railway.internal:5432/railway";
        }

        try {
            // Parsování DATABASE_URL (postgresql://user:pass@host:port/db)
            $url = parse_url($databaseUrl);
            
            $host = $url['host'];
            $port = $url['port'] ?? 5432;
            $db   = ltrim($url['path'], '/');
            $user = $url['user'];
            $pass = $url['pass'];

            $dsn = "pgsql:host=$host;port=$port;dbname=$db";
            
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            return self::$pdo;
        } catch (Exception $e) {
            die("Chyba připojení k PostgreSQL: " . $e->getMessage());
        }
    }

    public static function query($sql, $params = []) {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
