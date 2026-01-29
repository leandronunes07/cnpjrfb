<?php
$host = 'my_mysql8';
$db   = 'cnpjrfb_2026';
$user = 'root';
$pass = '123456';
$charset = 'utf8mb4';

echo "Tentando conectar em $host...\n";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "✅ SUCESSO: Conexão com MySQL 8 estabelecida!\n";
    
    // Check if database is empty
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll();
    echo "📊 Status do Banco:\n";
    echo "   Tabelas encontradas: " . count($tables) . "\n";
    foreach($tables as $t) {
        echo "   - " . reset($t) . "\n";
    }

} catch (\PDOException $e) {
    echo "❌ ERRO: Falha na conexão: " . $e->getMessage() . "\n";
    exit(1);
}
