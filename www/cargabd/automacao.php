<?php
/**
 * Script de Automação e Orquestração (Cron Job) - Versão Blue-Green (FINAL + UTF8 + ForceStart)
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
    private $logFile = '/tmp/cnpj_automacao.log'; 
    private $swapTriggerFile = '/var/www/html/cargabd/APPROVE_SWAP'; 

    public function __construct() {
        $this->initDatabase();
        $this->ensureControlTableExists();
    }

    private function log($msg, $type = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $formattedMsg = "[$timestamp] [$type] $msg";
        echo $formattedMsg . PHP_EOL;
        @file_put_contents($this->logFile, $formattedMsg . PHP_EOL, FILE_APPEND);
    }

    private function initDatabase() {
        $host = getenv('DB_HOST');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASSWORD');
        $dbName = getenv('DB_NAME');
        $port = getenv('DB_PORT') ?: 3306;

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, 
                PDO::ATTR_PERSISTENT => false
            ];

            $this->pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, $options);
            
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
            approval_token VARCHAR(64) NULL,
            tentativas INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        
        $this->pdo->exec($sql);
        
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$this->controlTable} LIKE 'approval_token'");
            $stmt->execute();
            if ($stmt->rowCount() == 0) {
                 $this->pdo->exec("ALTER TABLE {$this->controlTable} ADD COLUMN approval_token VARCHAR(64) NULL");
            }
            $stmt->closeCursor();
        } catch (Exception $e) {}
    }
    
    public function run() {
        $this->log("---- Iniciando Ciclo Blue-Green ----");
        
        $latestFolder = $this->getLatestFolderFromRFB();
        
        if ($latestFolder) {
            $this->log("Pasta mais recente na RFB: $latestFolder");
            $state = $this->checkState($latestFolder);
            
            switch ($state['status']) {
                case 'NEW':
                    $this->handleNewDetection($latestFolder);
                    break;
                case 'PENDING_APPROVAL':
                    $this->handlePending($state);
                    break;
                case 'FORCE_START': // Manual override
                    $this->log("⚠️ Início forçado pelo usuário.");
                    $this->handleImportExecution($latestFolder);
                    break;
                case 'Processing (Temp)':
                case 'PROCESSING': 
                    $this->handleStaleProcessing($state);
                    break;
                case 'WAITING_VALIDATION':
                    $this->log("Aguardando validação para $latestFolder...");
                    break;
                case 'COMPLETED':
                    $this->log("Pasta $latestFolder já processada.");
                    break;
            }
        } else {
             $this->log("Falha ao detectar pasta na RFB.", 'WARNING');
        }
        
        if (file_exists($this->swapTriggerFile)) {
            $this->executeSwap();
        }
    }

    private function handleImportExecution($folder) {
        $dbMain = getenv('DB_NAME');
        $dbTemp = $dbMain . '_temp';

        $this->updateStatus($folder, 'Processing (Temp)', "Criando banco temporário $dbTemp...");

        try {
            $this->pdo->exec("DROP DATABASE IF EXISTS `$dbTemp`");
            $this->pdo->exec("CREATE DATABASE `$dbTemp` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            putenv("DB_NAME=$dbTemp"); 
            putenv("TRUNCATE_ON_START=true"); 

            $this->log("Iniciando Carga no banco: $dbTemp");
            
            $this->executeShell("cd /var/www/html/cargabd/download && bash download_files.sh");
            $this->executeShell("cd /var/www/html/cargabd/download && bash unzip_files.sh");
            $this->executeShell("php /var/www/html/cargabd/index.php"); 
            
            $token = bin2hex(random_bytes(16));
            $this->updateStatus($folder, 'WAITING_VALIDATION', "Carga temp concluída. Token gerado.", $token);
            
            $link = "https://cnpjrfb.agenciataruga.com/cargabd/approval_dashboard.php?token=$token";
            
            $body = "<h2>🚀 Validação Necessária (Blue-Green)</h2>
                A carga da pasta <b>$folder</b> foi concluída no banco temporário.<br><br>
                <b>Resumo:</b><br>
                O banco temporário está pronto para assumir. Clique abaixo para ver as estatísticas e aprovar a troca.<br><br>
                <a href='$link' style='background:#28a745;color:white;padding:12px 24px;text-decoration:none;border-radius:5px;display:inline-block;'>👉 ACESSAR PAINEL DE APROVAÇÃO</a>
                <br><br><small>Link seguro válido para esta importação.</small>";
                
            $this->sendEmail("⚠️ [Ação Necessária] Aprovar Versão $folder", $body);

        } catch (Exception $e) {
            $this->updateStatus($folder, 'ERROR', $e->getMessage());
            $this->sendEmail("❌ Falha na Carga Temp", $e->getMessage());
            $this->log($e->getMessage(), 'ERROR');
        }
    }

    private function executeSwap() {
        $this->log("Gatilho de SWAP detectado. Iniciando troca de bancos...");
        
        $dbMain = getenv('DB_NAME');
        $dbTemp = $dbMain . '_temp';
        $dbOld  = $dbMain . '_old';

        try {
            $tables = ['cnae', 'empresa', 'estabelecimento', 'motivo', 'municipio', 'natureza_juridica', 'pais', 'qualificacao_socio', 'simples', 'socio'];
            
            $this->log("Movendo tabelas de $dbTemp para $dbMain (Atomic Switch)...");
            
            $this->pdo->exec("DROP DATABASE IF EXISTS `$dbOld`");
            $this->pdo->exec("CREATE DATABASE `$dbOld`");
            
            foreach ($tables as $table) {
                try {
                     $this->pdo->exec("RENAME TABLE `$dbMain`.`$table` TO `$dbOld`.`$table`");
                } catch(Exception $ex) {
                     $this->log("Tabela $table não existia no main, pulando backup.");
                }
            }
            
            foreach ($tables as $table) {
                $this->pdo->exec("RENAME TABLE `$dbTemp`.`$table` TO `$dbMain`.`$table`");
            }
            
            $this->pdo->exec("DROP DATABASE `$dbTemp`");
            if (file_exists($this->swapTriggerFile)) {
                unlink($this->swapTriggerFile); 
            }
            
            $this->log("SWAP Concluído com Sucesso!");
            $this->sendEmail("✅ Deploy Finalizado", "O banco de produção foi atualizado para a nova versão. O antigo está em $dbOld.");

        } catch (Exception $e) {
            $this->log("Erro no SWAP: " . $e->getMessage(), 'ERROR');
        }
    }
    
    private function getLatestFolderFromRFB() {
         $html = @file_get_contents($this->baseUrl, false, stream_context_create([
            "ssl" => ["verify_peer"=>false, "verify_peer_name"=>false]
         ]));
         if (!$html) return null;
         preg_match_all('/href="([0-9]{4}-[0-9]{2})\/"/', $html, $matches);
         if (!empty($matches[1])) {
             $dates = $matches[1];
             rsort($dates);
             return $dates[0];
         }
         return null;
    }
    
    private function checkState($folder) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->controlTable} WHERE pasta_rfb = ?");
        $stmt->execute([$folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor(); // FIX: Garante liberação de buffer
        return $row ?: ['status' => 'NEW'];
    }
    
    private function handleNewDetection($folder) {
        $this->log("Nova pasta encontrada: $folder");
        $stmt = $this->pdo->prepare("INSERT INTO {$this->controlTable} (pasta_rfb, status, data_detectada) VALUES (?, 'PENDING_APPROVAL', NOW())");
        $stmt->execute([$folder]);
        $this->sendEmail("⚠️ Nova Atualização: $folder", "Detectada pasta <b>$folder</b> na RFB.<br>Importação em 3 dias.");
    }

    private function handlePending($state) {
        $folder = $state['pasta_rfb'];
        $detectDate = new DateTime($state['data_detectada']);
        $now = new DateTime();
        $diff = $now->diff($detectDate);
        $this->log("Verificando $folder. Dias em espera: " . $diff->days);
        if ($diff->days >= 3) {
            $this->log("Prazo atingido. Iniciando IMPORTAÇÃO.");
            $this->handleImportExecution($folder);
        } else {
            $this->log("Ainda aguardando prazo de 3 dias.");
        }
    }

    private function handleStaleProcessing($state) {
        $folder = $state['pasta_rfb'];
         $this->log("ALERTA: Pasta $folder encontrada em estado PROCESSING.", 'WARNING');
         $this->handleImportExecution($folder);
    }

    private function updateStatus($folder, $status, $log = '', $token = null) {
        $sql = "UPDATE {$this->controlTable} SET status = ?, log = ?, tentativas = tentativas + 1";
        $params = [$status, $log];
        if ($token) { $sql .= ", approval_token = ?"; $params[] = $token; }
        $sql .= " WHERE pasta_rfb = ?"; $params[] = $folder;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function executeShell($command) {
        $output = []; $return_var = 0;
        exec($command . " 2>&1", $output, $return_var);
        $fullOutput = implode("\n", $output);
        @file_put_contents($this->logFile, "[$command] OUTPUT:\n$fullOutput\n", FILE_APPEND);
        if ($return_var !== 0) throw new Exception("Comando falhou ($return_var): $command");
        return true;
    }

    private function sendEmail($subject, $body) {
        $to = getenv('ADMIN_EMAIL');
        if (!$to) return;
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8'; // FIX: Encoding
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER');
            $mail->Password = getenv('SMTP_PASS');
            $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = getenv('SMTP_PORT') ?: 465;
            $mail->setFrom(getenv('SMTP_USER'), 'CNPJ Robot');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = nl2br($body);
            $mail->send();
        } catch (Exception $e) {}
    }
}

$automacao = new Automacao();
$automacao->run();
