<?php
define('DS', DIRECTORY_SEPARATOR);
// ROOT_PATH now points to the current directory (www/cargabd)
define('ROOT_PATH', __DIR__);

require_once __DIR__ . '/controllers/autoload_cargabd_cnpjrfb.php';

try {
    $pdo = TPDOConnection::connect();
    if (!$pdo) {
        die("Failed to connect to DB.");
    }
    print_r($pdo->query('SELECT status, count(*) FROM controle_arquivos GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
