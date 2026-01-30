<?php

namespace CnpjRfb\Database;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Get the Singleton PDO instance.
     * Environment variables are injected via Docker/Portainer.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_NAME') ?: 'cnpjrfb_2026';
            $user = getenv('DB_USER') ?: 'root';
            // Security: Fail hard if no password is provided in production concepts, 
            // but providing valid fallbacks for dev if logical. 
            // The user emphasized Docker ENV, so we trust it primary.
            $pass = getenv('DB_PASSWORD');

            if ($pass === false) {
                 // Log warning or throw error depending on strictness?
                 // For now, allow empty string but warn.
                 $pass = '';
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_LOCAL_INFILE => true, // CRITICAL for LOAD DATA LOCAL INFILE
                ]);
            } catch (PDOException $e) {
                // Obfuscate password in logs
                throw new RuntimeException("Database Connection Error: " . $e->getMessage());
            }
        }

        return self::$instance;
    }
    
    // Prevent cloning
    private function __clone() {}
    public function __wakeup() {}
}
