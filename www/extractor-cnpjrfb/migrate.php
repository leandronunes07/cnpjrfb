<?php

require_once __DIR__ . '/vendor/autoload.php';

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use Dotenv\Dotenv;

// Load .env if exists (Fallback for local dev)
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

Logger::log("Starting Database Migration...", 'info');

try {
    $pdo = Database::getInstance();
    
    $sqlFile = __DIR__ . '/sql/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Schema file not found: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    
    // Execute raw SQL
    // Splitting by ; might be needed if PDO doesn't handle multiple statements well in all drivers,
    // but MySQL usually handles it if emulation is off or specific flags set.
    // Ideally we execute statement by statement, but for schema creation massive blocks are okay often.
    
    $pdo->exec($sql);
    
    Logger::log("Migration completed successfully!", 'info');

} catch (Exception $e) {
    Logger::log("Migration Failed: " . $e->getMessage(), 'error');
    exit(1);
}
