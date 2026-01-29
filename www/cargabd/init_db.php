<?php
define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', __DIR__); // Now we are inside www/cargabd

require_once __DIR__ . '/controllers/autoload_cargabd_cnpjrfb.php';
// Initial connection without DB selected to create it if needed
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '123456';
$dbName = getenv('DB_NAME') ?: 'cnpjrfb_2026';

try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connected to MySQL server.\n";

    // Create Schema
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `$dbName` created or already exists.\n";
    
    // Select DB
    $pdo->exec("USE `$dbName`");

    // Load SQL Files
    // Adjust paths: we are in www/cargabd, modelo_banco is in ../../modelo_banco
    $files = [
        __DIR__ . '/../../modelo_banco/mysql/modelo_fixed.sql', 
        __DIR__ . '/../../modelo_banco/mysql/monitoramento.sql'
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) {
            echo "Skipping missing file: $file\n";
            continue;
        }
        
        echo "Importing $file...\n";
        $sql = file_get_contents($file);
        
        // Remove hardcoded schema creation/use from modelo_fixed if present to align with our dynamic DB name
        // However, modelo_fixed uses `cnpjrfb_2026`. We should replace that with our target DB name just in case.
        $sql = str_replace('`cnpjrfb_2026`', "`$dbName`", $sql);
        $sql = str_replace('`dados_rfb`', "`$dbName`", $sql); // ConfigHelper might have used this old name

        // Execute multiple statements
        $pdo->exec($sql);
        echo "Imported $file successfully.\n";
    }

    echo "Database initialization completed.\n";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}
