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
    private $logFile = '/tmp/cnpj_automacao.log'; // Usando /tmp para evitar erro de permissão no Windows Bind Mount
    private $swapTriggerFile = '/var/www/html/cargabd/APPROVE_SWAP'; 

    public function __construct() {
        // Init DB
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
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Importante para evitar erro 2014
                PDO::ATTR_PERSISTENT => false
            ];

            // Connect
            $this->pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, $options);
            
            // Create/Use DB
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
        
        // Ensure approval_token column exists (for updates)
        try {
             $this->pdo->exec("SELECT approval_token FROM {$this->controlTable} LIMIT 1");
        } catch (Exception $e) {
             $this->pdo->exec("ALTER TABLE {$this->controlTable} ADD COLUMN approval_token VARCHAR(64) NULL");
        }
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
                case 'Processing (Temp)':
                case 'PROCESSING': // Legacy support
                    $this->handleStaleProcessing($state);
                    break;
                case 'WAITING_VALIDATION':
                    // Just wait or re-send email reminder if needed
                    $this->log("Aguardando validação para $latestFolder...");
                    break;
                case 'COMPLETED':
                    $this->log("Pasta $latestFolder já processada.");
                    break;
            }
        } else {
             $this->log("Falha ao detectar pasta na RFB.", 'WARNING');
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
            
            $this->executeShell("cd /var/www/html/cargabd/download && ./download_files.sh");
            $this->executeShell("cd /var/www/html/cargabd/download && ./unzip_files.sh");
            $this->executeShell("php /var/www/html/cargabd/index.php"); 
            
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
            $this->log($e->getMessage(), 'ERROR');
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
                // Verifica se tabela existe no main antes
                try {
                     $this->pdo->exec("RENAME TABLE `$dbMain`.`$table` TO `$dbOld`.`$table`");
                } catch(Exception $ex) {
                     $this->log("Tabela $table não existia no main, pulando backup.");
                }
            }
            
            // 3. Move Temp -> Current
            foreach ($tables as $table) {
                $this->pdo->exec("RENAME TABLE `$dbTemp`.`$table` TO `$dbMain`.`$table`");
            }
            
            // 4. Limpeza
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
    
    // --- Helpers ---

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
        $stmt->closeCursor(); // Garante liberação de buffer
        return $row ?: ['status' => 'NEW'];
    }

    private function handleNewDetection($folder) {
        $this->log("Nova pasta encontrada: $folder");
        
        $stmt = $this->pdo->prepare("INSERT INTO {$this->controlTable} (pasta_rfb, status, data_detectada) VALUES (?, 'PENDING_APPROVAL', NOW())");
        $stmt->execute([$folder]);
        
        $this->sendEmail(
            "⚠️ Nova Atualização: $folder",
            "Detectada pasta <b>$folder</b> na RFB.<br>Importação em 3 dias."
        );
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
        
        if ($state['tentativas'] < 3) {
             $this->log("Tentativa de autorecuperação ({$state['tentativas']}/3).");
             // Retry
             $this->handleImportExecution($folder);
        } else {
             $this->updateStatus($folder, 'ERROR', "Falha Crítica: Processo travou repetidamente.");
             $this->sendEmail("❌ Falha Crítica: $folder", "O processo travou 3 vezes.");
        }
    }

    private function updateStatus($folder, $status, $log = '', $token = null) {
        $sql = "UPDATE {$this->controlTable} SET status = ?, log = ?, tentativas = tentativas + 1";
        $params = [$status, $log];
        if ($token) {
            $sql .= ", approval_token = ?";
            $params[] = $token;
        }
        $sql .= " WHERE pasta_rfb = ?";
        $params[] = $folder;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    private function executeShell($command) {
        $output = [];
        $return_var = 0;
        exec($command . " 2>&1", $output, $return_var);
        
        $fullOutput = implode("\n", $output);
        file_put_contents($this->logFile, "[$command] OUTPUT:\n$fullOutput\n", FILE_APPEND);
        
        if ($return_var !== 0) {
            throw new Exception("Comando falhou ($return_var): $command. Veja logs.");
        }
        return true;
    }
    
    private function getLastLogs($lines = 20) {
        if (!file_exists($this->logFile)) return "Sem logs.";
        $data = file($this->logFile);
        return implode("", array_slice($data, -$lines));
    }

    private function sendEmail($subject, $body) {
        $to = getenv('ADMIN_EMAIL');
        if (!$to) {
            $this->log("Email admin não configurado.", 'WARNING');
            return;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = getenv('SMTP_HOST');
            $mail->SMTPAuth   = true;
            $mail->Username   = getenv('SMTP_USER');
            $mail->Password   = getenv('SMTP_PASS');
            $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = getenv('SMTP_PORT') ?: 465;

            $mail->setFrom(getenv('SMTP_USER'), 'CNPJ Robot');
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = nl2br($body);

            $mail->send();
            $this->log("Email enviado para $to: $subject");
        } catch (Exception $e) {
            $this->log("Erro ao enviar email: " . $mail->ErrorInfo, 'ERROR');
        }
    }
}

// Run
$automacao = new Automacao();
$automacao->run();
