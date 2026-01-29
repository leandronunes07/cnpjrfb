<?php
// Simple Import Test: Just CNAE (Simplified)

define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', __DIR__);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/ArrayHelper.class.php';
require_once __DIR__ . '/helpers/StringHelper.class.php';
require_once __DIR__ . '/helpers/ConfigHelper.class.php';
require_once __DIR__ . '/controllers/TPDOConnection.class.php';
require_once __DIR__ . '/controllers/UploadCsv.class.php';
require_once __DIR__ . '/dao/Dao.class.php';
require_once __DIR__ . '/dao/CnaeDAO.class.php';

// Setup Connection
try {
    $tpdo = New TPDOConnection();
    $tpdo::connect();
    echo "✅ Conexão Banco OK\n";
} catch (Exception $e) {
    die("❌ Erro Conexão: " . $e->getMessage() . "\n");
}

// Setup DAO
$cnaeDao = new CnaeDAO($tpdo);
echo "✅ DAO Instanciado\n";

// Find File
$path = '/var/www/html/cargabd/extracted';
$files = glob($path . '/*CNAECSV*');

if (empty($files)) {
    die("❌ Arquivo CNAECSV não encontrado em $path\n");
}

$file = $files[0];
echo "📂 Arquivo encontrado: " . basename($file) . "\n";

// Execute Import
echo "🚀 Iniciando Importação (UploadCsv direta)...\n";
try {
    $upload = new UploadCsv($cnaeDao, $file);
    $count = $upload->executar();
    echo "✅ Importação concluída! Registros processados: $count\n";
} catch (Exception $e) {
    die("❌ Erro Importação: " . $e->getMessage() . "\n");
}
