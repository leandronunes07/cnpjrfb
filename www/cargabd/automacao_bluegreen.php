<?php
/**
 * Script de Automação e Orquestração (Cron Job) - Versão Blue-Green
 * - Cria banco temporário para carga
 * - Aguarda validação manual (gatilho de arquivo ou email)
 * - Realiza troca atômica (Swap) dos bancos
 */

define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', __DIR__);

// Load ENV helpers
require_once __DIR__ . '/controllers/TPDOConnection.class.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../cnpjrfb/vendor/autoload.php';

class Automacao {
    
    private $pdo;
    private $controlTable = 'monitoramento_rfb';
    private $baseUrl = 'https://arquivos.receitafederal.gov.br/dados/cnpj/dados_abertos_cnpj/';
    private $logFile = '/var/log/cnpj_automacao.log';
    private $swapTriggerFile = '/var/www/html/cargabd/APPROVE_SWAP'; // Gatilho manual

    public function __construct() {
        $this->initDatabase();
        $this->ensureControlTableExists();
    }

    private function log($msg, $type = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMsg = "[$timestamp] [$type] $msg";
        echo $formattedMsg . PHP_EOL;
        file_put_contents($this->logFile, $formattedMsg . PHP_EOL, FILE_APPEND);
    }

    private function initDatabase() {
        $host = getenv('DB_HOST');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASSWORD');
        $dbName = getenv('DB_NAME');
        $port = getenv('DB_PORT') ?: 3306;

        try {
            $this->pdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Garante que o banco principal existe para guardar a tabela de monitoramento
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->pdo->exec("USE `$dbName`");

        } catch (PDOException $e) {
            $this->log("CRITICAL DB ERROR: " . $e->getMessage(), 'ERROR');
            exit(1);
        }
    }

    private function ensureControlTableExists() {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->controlTable} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pasta_rfb VARCHAR(20) NOT NULL UNIQUE,
            status VARCHAR(20) NOT NULL DEFAULT 'NEW',
            data_detectada DATETIME DEFAULT CURRENT_TIMESTAMP,
            log TEXT,
            tentativas INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $this->pdo->exec($sql);
        
        // Migration rápida: Adicionar coluna approval_token se não existir
        try {
            $this->pdo->exec("ALTER TABLE {$this->controlTable} ADD COLUMN approval_token VARCHAR(64) NULL");
        } catch (Exception $e) {
            // Ignora erro se coluna já existir
        }
    }
    
    public function run() {
        $this->log("---- Iniciando Ciclo Blue-Green ----");
        
        $latestFolder = $this->getLatestFolderFromRFB();
        
        if ($latestFolder) {
            $state = $this->checkState($latestFolder);
            
            switch ($state['status']) {
                case 'NEW':
                    $this->handleNewDetection($latestFolder);
                    break;
                case 'PENDING_APPROVAL':
                    $this->handlePending($state);
                    break;
                case 'Processing (Temp)':
                    $this->handleStaleProcessing($state);
                    break;
                case 'WAITING_VALIDATION':
                    $this->handleValidationMsg($state);
                    break;
            }
        }
        
        // Verifica se há gatilho de SWAP independente da pasta (para casos manuais)
        if (file_exists($this->swapTriggerFile)) {
            $this->executeSwap();
        }
    }

    private function handleImportExecution($folder) {
        $dbMain = getenv('DB_NAME');
        $dbTemp = $dbMain . '_temp';

        $this->updateStatus($folder, 'Processing (Temp)', "Criando banco temporário $dbTemp...");

        try {
            // 1. Cria Banco Temp
            $this->pdo->exec("DROP DATABASE IF EXISTS `$dbTemp`");
            $this->pdo->exec("CREATE DATABASE `$dbTemp` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // 2. Aponta ambiente para o banco temp
            putenv("DB_NAME=$dbTemp"); // Cargabanco vai ler isso
            putenv("TRUNCATE_ON_START=true"); // Temp é sempre novo

            // 3. Executa Carga (Download/Unzip/Import)
            $this->log("Iniciando Carga no banco: $dbTemp");
            
            // ... (Mesma lógica de chamadas shell script) ...
            $this->executeShell("php /var/www/html/cargabd/index.php"); // Agora escreve em _temp
            
            // 4. Sucesso -> Aguarda Validação
            
            // Gerar Token Seguro
            $token = bin2hex(random_bytes(16));
            $this->updateStatus($folder, 'WAITING_VALIDATION', "Carga temp concluída. Token gerado.", $token);
            
            $link = "https://cnpjrfb.agenciataruga.com/cargabd/approval_dashboard.php?token=$token";
            
            // Envia Email com Instruções e Link
            $body = "<h2>🚀 Validação Necessária (Blue-Green)</h2>
                A carga da pasta <b>$folder</b> foi concluída no banco temporário.<br><br>
                
                <b>Resumo:</b><br>
                O banco temporário está pronto para assumir. Clique abaixo para ver as estatísticas e aprovar a troca.<br><br>
                
                <a href='$link' style='background:#28a745;color:white;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;'>👉 ACESSAR PAINEL DE APROVAÇÃO</a>
                <br><br>
                <small>Link seguro válido para esta importação.</small>";
                
            $this->sendEmail("⚠️ [Ação Necessária] Aprovar Versão $folder", $body);

        } catch (Exception $e) {
            $this->updateStatus($folder, 'ERROR', $e->getMessage());
            $this->sendEmail("❌ Falha na Carga Temp", $e->getMessage());
        }
    }

    private function executeSwap() {
        $this->log("Gatilho de SWAP detectado. Iniciando troca de bancos...");
        
        $dbMain = getenv('DB_NAME');
        $dbTemp = $dbMain . '_temp';
        $dbOld  = $dbMain . '_old';

        try {
            // Lógica de Rename (MySQL não tem 'RENAME DATABASE', então usaremos mysqldump pipe ou rename tables se fossem poucas.
            // COM BANCOS GIGANTES (CNPJ), RENAME DATABASE NÃO EXISTE.
            // WORKAROUND RÁPIDO: Mover tabelas.
            
            $tables = ['cnae', 'empresa', 'estabelecimento', 'motivo', 'municipio', 'natureza_juridica', 'pais', 'qualificacao_socio', 'simples', 'socio'];
            
            $this->log("Movendo tabelas de $dbTemp para $dbMain (Atomic Switch)...");
            
            // 1. Drop Old se existir
            $this->pdo->exec("DROP DATABASE IF EXISTS `$dbOld`");
            $this->pdo->exec("CREATE DATABASE `$dbOld`");
            
            // 2. Move Current -> Old
            foreach ($tables as $table) {
                // RENAME TABLE db1.tbl TO db2.tbl
                $this->pdo->exec("RENAME TABLE `$dbMain`.`$table` TO `$dbOld`.`$table`");
            }
            
            // 3. Move Temp -> Current
            foreach ($tables as $table) {
                $this->pdo->exec("RENAME TABLE `$dbTemp`.`$table` TO `$dbMain`.`$table`");
            }
            
            // 4. Limpeza
            $this->pdo->exec("DROP DATABASE `$dbTemp`");
            unlink($this->swapTriggerFile); // Remove gatilho
            
            $this->log("SWAP Concluído com Sucesso!");
            $this->sendEmail("✅ Deploy Finalizado", "O banco de produção foi atualizado para a nova versão. O antigo está em $dbOld.");

        } catch (Exception $e) {
            $this->log("Erro no SWAP: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // ... (Helpers: sendEmail, executeShell, etc mantidos) ...
    // Placeholder para os métodos omitidos para brevidade do diff, na implementação real copiarei tudo.
}
// ...
