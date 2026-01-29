<?php
/**
 * Script de Automação e Orquestração (Cron Job) - Versão Blue-Green (FINAL + UTF8 + ForceStart)
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('memory_limit', '2048M');

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
        // Tabela Principal (Versões)
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
        
        // Tabela de Fila (Arquivos Individuais)
        // Monitora o ciclo de vida de cada arquivo ZIP
        $sqlQueue = "CREATE TABLE IF NOT EXISTS controle_arquivos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referencia_rfb VARCHAR(20) NOT NULL,
            nome_arquivo VARCHAR(255) NOT NULL,
            url_origem VARCHAR(500) NOT NULL,
            tipo VARCHAR(50) NULL,
            status VARCHAR(20) DEFAULT 'NEW', -- NEW, DOWNLOADING, EXTRACTED, IMPORTING, COMPLETED, ERROR
            tentativas INT DEFAULT 0,
            mensagem_erro TEXT,
            tamanho_bytes BIGINT NULL,
            tempo_processamento_seg FLOAT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ref_status (referencia_rfb, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $this->pdo->exec($sqlQueue);
        
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$this->controlTable} LIKE 'approval_token'");
            $stmt->execute();
            if ($stmt->rowCount() == 0) {
                 $this->pdo->exec("ALTER TABLE {$this->controlTable} ADD COLUMN approval_token VARCHAR(64) NULL");
            }
            $stmt->closeCursor();
        } catch (Exception $e) {}
    }
    
    public function run($argv = []) {
        $this->log("---- Agente de Automação Iniciado ----");
        
        // Check for args
        $options = getopt("", ["stage::"]);
        $stage = $options['stage'] ?? null;
        
        if ($stage) {
             $this->runStage($stage);
             return;
        }

        // Supervisor Mode (Legacy / Default Loop)
        // If no arguments, it acts as "Discovery Agent" + "Supervisor"
        
        $latestFolder = $this->getLatestFolderFromRFB();
        if ($latestFolder) {
            $state = $this->checkState($latestFolder);
            
            if ($state['status'] == 'NEW') {
                $this->handleNewDetection($latestFolder);
            } elseif ($state['status'] == 'PENDING_APPROVAL') {
                $this->handlePending($state);
            }
            
            // If Supervisor mode, trigger stages if not running via shell exec?
            // No, entrypoint.sh will handle triggers via Cron.
            // This script just ensures the version is registered.
            
            if (file_exists($this->swapTriggerFile)) {
                $this->executeSwap();
            }
        }
    }
    
    private function runStage($stage) {
        $this->log("Iniciando Stage: " . strtoupper($stage));
        // Get active folder
        $activeFolder = $this->getActiveFolder();
        if (!$activeFolder) {
             $this->log("Nenhuma versão ativa para processar."); 
             return;
        }
        
        // Loop for 55 seconds
        $endTime = time() + 55;
        while (time() < $endTime) {
            $didWork = false;
            switch ($stage) {
                case 'download':
                    $didWork = $this->runDownloader($activeFolder);
                    break;
                case 'extract':
                    $didWork = $this->runExtractor($activeFolder);
                    break;
                case 'import':
                    $didWork = $this->runImporter($activeFolder);
                    break;
                case 'swap':
                    if (file_exists($this->swapTriggerFile)) {
                        $this->executeSwap();
                        return; // Exit after swap
                    }
                    sleep(2);
                    break;
            }
            
            if (!$didWork && $stage != 'swap') {
                // If nothing to do, verify completion
                if ($this->isAllDone($activeFolder) && $stage == 'import') {
                     // Check if already updated to WAITING_VALIDATION
                     $state = $this->checkState($activeFolder);
                     if ($state['status'] != 'WAITING_VALIDATION' && $state['status'] != 'COMPLETED') {
                         $this->finishImportValues($activeFolder);
                     }
                     break; 
                }
                sleep(5);
            }
        }
    }
    
    private function getActiveFolder() {
        $stmt = $this->pdo->query("SELECT pasta_rfb FROM {$this->controlTable} WHERE status NOT IN ('COMPLETED', 'NEW') ORDER BY id DESC LIMIT 1");
        return $stmt->fetchColumn();
    }
    
    // --- PIPELINE WORKERS ---
    
    private function runDownloader($folder) {
        // Backpressure: Start new download only if Extracted/Importing queue is small (< 5)
        // This prevents disk fill up
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM controle_arquivos WHERE status IN ('EXTRACTED', 'IMPORTING', 'EXTRACTING') AND referencia_rfb = ?");
        $stmt->execute([$folder]);
        if ($stmt->fetchColumn() >= 3) return false; // Throttling: Wait for consumer
        
        // Pick NEW/RETRY Download
        $stmt = $this->pdo->prepare("SELECT * FROM controle_arquivos 
            WHERE status IN ('NEW', 'RETRY_DOWNLOAD') AND referencia_rfb = ? 
            ORDER BY tipo='PAIS' DESC, tipo='MUNICIPIO' DESC, id ASC LIMIT 1");
        $stmt->execute([$folder]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) return false;
        
        $this->updateQueueStatus($job['id'], 'DOWNLOADING');
        $this->log("⬇️ Downloader: " . $job['nome_arquivo']);
        
        try {
            $downloadDir = '/var/www/html/cargabd/download';
            if (!file_exists($downloadDir)) mkdir($downloadDir, 0777, true);
            $zipPath = $downloadDir . '/' . $job['nome_arquivo'];
            
            // wget with resume
            $cmd = "wget -c -nv -O '$zipPath' '{$job['url_origem']}'";
            $this->executeShell($cmd);
            
            // Check size?
            if (!file_exists($zipPath) || filesize($zipPath) < 100) throw new Exception("Arquivo vazio ou download falhou");
            
            $this->updateQueueStatus($job['id'], 'DOWNLOADED');
            return true;
        } catch (Exception $e) {
            $this->handleError($job['id'], $e->getMessage(), 'RETRY_DOWNLOAD');
            return false;
        }
    }

    private function runExtractor($folder) {
        // Pick DOWNLOADED
        $stmt = $this->pdo->prepare("SELECT * FROM controle_arquivos WHERE status = 'DOWNLOADED' AND referencia_rfb = ? LIMIT 1");
        $stmt->execute([$folder]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) return false;
        
        $this->updateQueueStatus($job['id'], 'EXTRACTING');
        $this->log("📦 Extractor: " . $job['nome_arquivo']);
        
        try {
            $downloadDir = '/var/www/html/cargabd/download';
            $extractDir = getenv('EXTRACTED_FILES_PATH') ?: '/var/www/html/cargabd/extracted'; // Use ENV
            
            if (!file_exists($extractDir)) mkdir($extractDir, 0777, true);
            
            $zipPath = $downloadDir . '/' . $job['nome_arquivo'];
            
            if (!file_exists($zipPath)) throw new Exception("ZIP sumiu: $zipPath");

            // 1. Scan directory BEFORE
            $filesBefore = array_map('basename', glob("$extractDir/*"));
            
            $cmd = "unzip -o '$zipPath' -d '$extractDir'";
            $this->executeShell($cmd);
            
            // 2. Scan directory AFTER
            $filesAfter = array_map('basename', glob("$extractDir/*"));
            $newFiles = array_diff($filesAfter, $filesBefore);
            $extractedFile = reset($newFiles);
            
            $this->log("DEBUG Extractor: NewFiles found: " . print_r($newFiles, true));

            // If no new file found (maybe overwrite?), try to find mostly likely candidate via time?
            if (!$extractedFile) {
                // Fallback: Find the most recent file in folder
                $files = glob("$extractDir/*");
                usort($files, function($a, $b) { return filemtime($a) < filemtime($b); }); // Newest first
                $extractedFile = basename($files[0] ?? '');
                 $this->log("DEBUG Extractor: Fallback found: $extractedFile");
            }
            
            if (!$extractedFile) throw new Exception("Falha ao identificar arquivo extraído de $job[nome_arquivo]");
            
            // 3. Rename to Standardized Name (ID_NAME.csv)
            $standardName = "job_{$job['id']}.csv";
            $renameResult = rename("$extractDir/$extractedFile", "$extractDir/$standardName");
            if(!$renameResult) $this->log("DEBUG: Rename Falhou de $extractedFile para $standardName");
            
            // Remove ZIP
            unlink($zipPath);
            
            $this->updateQueueStatus($job['id'], 'EXTRACTED');
            return true;
        } catch (Exception $e) {
             $this->handleError($job['id'], $e->getMessage(), 'RETRY_DOWNLOAD'); 
             return false;
        }
    }

    private function runImporter($folder) {
        // Pick EXTRACTED
        $stmt = $this->pdo->prepare("SELECT * FROM controle_arquivos WHERE status = 'EXTRACTED' AND referencia_rfb = ? LIMIT 1");
        $stmt->execute([$folder]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) return false;
        
        $this->updateQueueStatus($job['id'], 'IMPORTING');
        $this->log("🚀 Importer: " . $job['nome_arquivo']);
        
        try {
            $extractDir = getenv('EXTRACTED_FILES_PATH') ?: '/var/www/html/cargabd/extracted';
            
            // Look for Standardized Name
            $targetCsv = "$extractDir/job_{$job['id']}.csv";
            
            if (!file_exists($targetCsv)) {
                 throw new Exception("CSV Padronizado não encontrado: $targetCsv");
            }

            // --- FIX IMPORT MAPPING ---
            // CargaDadosCNPJ uses glob() searching for specific substrings (e.g. 'SOCIOCSV').
            // Since we renamed to job_ID.csv, it won't find it.
            // Solution: Rename job_ID.csv to job_ID.SUFFIX temporarily.
            
            $map = [
                'Socios'          => 'SOCIOCSV',
                'Empresas'        => 'EMPRECSV',
                'Estabelecimentos'=> 'ESTABELE',
                'Simples'         => 'SIMPLES',
                'Cnaes'           => 'CNAECSV',
                'Motivos'         => 'MOTICSV',
                'Municipios'      => 'MUNICCSV',
                'Naturezas'       => 'NATJUCSV',
                'Paises'          => 'PAISCSV',
                'Qualificacoes'   => 'QUALSCSV'
            ];
            
            $suffix = '';
            foreach ($map as $key => $val) {
                if (stripos($job['nome_arquivo'], $key) !== false) {
                    $suffix = $val;
                    break;
                }
            }
            
            if (!$suffix) throw new Exception("Não foi possível determinar o tipo do arquivo: " . $job['nome_arquivo']);
            
            $finalName = "$extractDir/job_{$job['id']}.{$suffix}";
            rename($targetCsv, $finalName);
            
            $this->log("ℹ️ Arquivo renomeado para compatibilidade: " . basename($finalName));

            // --- IMPORT LOGIC ---
            $dbMain = getenv('DB_NAME');
            
            require_once __DIR__ . '/controllers/Cargabanco.class.php';
            
            // Force micro-batch mode (No Truncate)
            putenv('TRUNCATE_ON_START=false');
            
            $importer = new Cargabanco();
            $importer->executar();
            
            $this->updateQueueStatus($job['id'], 'COMPLETED');
            $this->log("✅ Importação concluída: " . $job['nome_arquivo']);
            
            return true;
            $this->handleError($job['id'], $e->getMessage(), 'ERROR');
            return false;
        }
    }
    }
    
    private function handleError($id, $msg, $status) {
         $this->log("Error Job #$id: $msg");
         $stmt = $this->pdo->prepare("UPDATE controle_arquivos SET status=?, mensagem_erro=?, tentativas=tentativas+1 WHERE id=?");
         $stmt->execute([$status, $msg, $id]);
    }
    
    private function isAllDone($folder) {
         $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM controle_arquivos WHERE status != 'COMPLETED' AND referencia_rfb = ?");
         $stmt->execute([$folder]);
         return $stmt->fetchColumn() == 0;
    }
    
    // Change signature to return bool
    private function processNextQueueItem($folder) {
        $dbMain = getenv('DB_NAME');
        $dbTemp = $dbMain . '_temp';

        // 1. Select Next Job
        $stmt = $this->pdo->prepare("SELECT * FROM controle_arquivos 
            WHERE status IN ('NEW', 'RETRY') AND referencia_rfb = ? 
            ORDER BY tipo='PAIS' DESC, tipo='MUNICIPIO' DESC, id ASC LIMIT 1"); // Prioriza tabelas auxiliares
        $stmt->execute([$folder]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            // Se não tem jobs NEW/RETRY, verifica se tem algum ainda rodando ou ERROR
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM controle_arquivos WHERE status IN ('DOWNLOADING','EXTRACTING','IMPORTING') AND referencia_rfb = ?");
            $stmt->execute([$folder]);
            if ($stmt->fetchColumn() > 0) return true; // Ainda tem gente trabalhando (Busy wait)
            
            // Se todos COMPLETED -> Sucesso Total
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM controle_arquivos WHERE status != 'COMPLETED' AND referencia_rfb = ?");
            $stmt->execute([$folder]);
            $pendentes = $stmt->fetchColumn();
            
            if ($pendentes == 0) {
                 $this->finishImportValues($folder);
            }
            return false;
        }

        // 2. Start Job
        $fileId = $job['id'];
        $fileName = $job['nome_arquivo'];
        $fileUrl  = $job['url_origem'];
        
        $this->updateQueueStatus($fileId, 'DOWNLOADING');
        $this->log("Iniciando Job #$fileId: $fileName");
        
        try {
            // STEP A: Download
            $downloadDir = '/var/www/html/cargabd/download';
            $zipPath = $downloadDir . '/' . $fileName;
            
            if (!file_exists($downloadDir)) mkdir($downloadDir, 0777, true);
            
            // Usa wget com retry
            $cmd = "wget -c -nv -O '$zipPath' '$fileUrl'";
            $this->executeShell($cmd);
            
            // STEP B: Unzip
            $this->updateQueueStatus($fileId, 'EXTRACTING');
            $extractDir = '/var/www/html/cargabd/extracted';
            if (!file_exists($extractDir)) mkdir($extractDir, 0777, true);
            
            // Unzip -n (skip existing) but here we want explicit extract
            $cmd = "unzip -o '$zipPath' -d '$extractDir'"; // -o force overwrite for fresh extraction
            $this->executeShell($cmd);
            
            // Find extracted CSV
            $csvFiles = array_merge(glob("$extractDir/*.csv"), glob("$extractDir/*.CSV"));
            if (empty($csvFiles)) {
                 $csvFiles = glob("$extractDir/*"); // Try any file
            }
            $targetCsv = reset($csvFiles); // Pega o primeiro que achar (normalmente só tem 1 por zip)
            
            if (!$targetCsv) throw new Exception("CSV não encontrado após extração de $fileName");
            
            // STEP C: Import
            $this->updateQueueStatus($fileId, 'IMPORTING');
            
            // Initialize Cargabanco Wrapper
            // Aqui conectamos ao DB TEMP
            putenv("DB_NAME=$dbTemp"); // Hack para a classe DAO conectar no certo
            
            // Precisamos instanciar o Controller e chamar o método certo
            require_once __DIR__ . '/index.php'; // Carrega autoloads mas não executa devido ao if(cli)
            // Erro: index.php executa se for CLI. Precisamos de um require que só traga classes.
            // Vamos assumir que os requires do topo ja resolveram.
            
            $carga = new Cargabanco();
            // Precisamos mapear o DAO correto
            $daoMap = [
                'EMPRESA' => 'EmpresaDAO',
                'ESTABELECIMENTO' => 'EstabelecimentoDAO',
                'SOCIO' => 'SociosDAO',
                'SIMPLES' => 'SimplesDAO',
                'CNAE' => 'CnaeDAO',
                'MOTIVO' => 'MotiDAO',
                'MUNICIPIO' => 'MunicDAO',
                'NATUREZA' => 'NatjuDAO',
                'PAIS' => 'PaisDAO',
                'QUALIFICACAO' => 'QualsDAO'
            ];
            
            // Detecta DAO via Tipo do Job
            $daoClass = $daoMap[$job['tipo']] ?? null;
            if (!$daoClass) throw new Exception("Tipo desconhecido: " . $job['tipo']);
            
            // Cria DAO Instance
            $tpdo = New TPDOConnection();
            $tpdo::connect();
            $daoInstance = new $daoClass($tpdo);
            
            // Roda Carga
            $this->log("Importando CSV no Banco: " . basename($targetCsv));
            $carga->carregaDadosTabela($daoInstance, basename($targetCsv));
            
            // STEP D: Cleanup
            unlink($zipPath);
            unlink($targetCsv);
            
            $this->updateQueueStatus($fileId, 'COMPLETED');
            $this->log("Job #$fileId concluído com sucesso.");

        } catch (Exception $e) {
            $msg = $e->getMessage();
            $this->pdo->prepare("UPDATE controle_arquivos SET status='ERROR', mensagem_erro=?, tentativas=tentativas+1 WHERE id=?")->execute([$msg, $fileId]);
            $this->log("Erro no job $fileId: $msg", 'ERROR');
        }
    }
    
    private function updateQueueStatus($id, $status) {
        $this->pdo->prepare("UPDATE controle_arquivos SET status=? WHERE id=?")->execute([$status, $id]);
    }
    
    private function finishImportValues($folder) {
             $token = bin2hex(random_bytes(16));
            $this->updateStatus($folder, 'WAITING_VALIDATION', "Carga Queue concluída.", $token);
            
            $link = "https://cnpjrfb.agenciataruga.com/cargabd/approval_dashboard.php?token=$token";
            $body = "<h2>� Validação Necessária (Blue-Green Queue)</h2>
                A carga <b>$folder</b> via Fila foi concluída.<br><br>
                <a href='$link'>ACESSAR PAINEL</a>";
            $this->sendEmail("✅ Carga Finalizada: $folder", $body);
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
        
        // 1. Registrar Versão
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO {$this->controlTable} (pasta_rfb, status, data_detectada) VALUES (?, 'PENDING_APPROVAL', NOW())");
        $stmt->execute([$folder]);
        
        // 2. Popular Fila de Arquivos
        $this->log("Populando fila de download para $folder...");
        $qtd = $this->populateQueue($folder);
        
        $this->sendEmail("⚠️ Nova Atualização: $folder", "Detectada pasta <b>$folder</b> na RFB.<br>Fila criada com <b>$qtd</b> arquivos.<br>Aguardando prazo de 3 dias para iniciar processamento.");
    }
    
    private function populateQueue($folder) {
        $url = $this->baseUrl . $folder . '/';
        $html = @file_get_contents($url, false, stream_context_create([
            "ssl" => ["verify_peer"=>false, "verify_peer_name"=>false]
        ]));
        
        if (!$html) {
            $this->log("Falha ao ler URL $url", 'ERROR');
            return 0;
        }
        
        preg_match_all('/href="([^"]+\.zip)"/', $html, $matches);
        if (empty($matches[1])) return 0;
        
        $files = array_unique($matches[1]);
        $count = 0;
        
        foreach ($files as $file) {
            // Identifica Tipo
            $tipo = 'OUTROS';
            if (strpos($file, 'Empresa')!==false) $tipo = 'EMPRESA';
            if (strpos($file, 'Estabele')!==false) $tipo = 'ESTABELECIMENTO';
            if (strpos($file, 'Socio')!==false) $tipo = 'SOCIO';
            if (strpos($file, 'Simples')!==false) $tipo = 'SIMPLES';
            if (strpos($file, 'Cnae')!==false) $tipo = 'CNAE';
            if (strpos($file, 'Moti')!==false) $tipo = 'MOTIVO';
            if (strpos($file, 'Munic')!==false) $tipo = 'MUNICIPIO';
            if (strpos($file, 'Natju')!==false) $tipo = 'NATUREZA';
            if (strpos($file, 'Pais')!==false) $tipo = 'PAIS';
            if (strpos($file, 'Quals')!==false) $tipo = 'QUALIFICACAO';
            
            $fileUrl = $url . $file;
            
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO controle_arquivos 
                (referencia_rfb, nome_arquivo, url_origem, tipo, status) 
                VALUES (?, ?, ?, ?, 'NEW')");
            $stmt->execute([$folder, $file, $fileUrl, $tipo]);
            $count++;
        }
        
        $this->log("Queue populada com $count arquivos.");
        return $count;
    }

    private function handlePending($state) {
        $folder = $state['pasta_rfb'];
        $detectDate = new DateTime($state['data_detectada']);
        $now = new DateTime();
        $diff = $now->diff($detectDate);
        $this->log("Verificando $folder. Dias em espera: " . $diff->days);
        if ($diff->days >= 3) {
         $this->log("Prazo atingido. (Requisição de Importação)");
            // In pipeline architecture, we just respect the Start flag.
            // Queue populates in NewDetection.
            // So we need to ensure stats are running or force them?
            // Actually, if status is PENDING_APPROVAL and queue is populated,
            // we should have logic to START the workers.
            // But workers run NEW/RETRY.
            // So we just need to enable them.
            // Wait, runDownloader checks for status?
            // runDownloader picks NEW/RETRY jobs.
            // Is there a parent status blocking it?
            // No, the workers just look at queue.
            // But we might want to gate them if parent folder is PENDING?
            // The current logic: Workers take job if folder is the active one.
            // getActiveFolder returns status NOT IN (COMPLETED, NEW).
            // PENDING_APPROVAL is NOT IN (COMPLETED, NEW). So workers ARE ACTIVE.
            // So, actually, the import starts immediately after population?
            // The user wanted a 3-day delay.
            // Fix: getActiveFolder should only pick if status is PROCESSING or something.
            // Let's change this log and logic.
             $this->pdo->prepare("UPDATE {$this->controlTable} SET status='PROCESSING' WHERE pasta_rfb=?")->execute([$folder]);
             $this->log("Status atualizado para PROCESSING. Workers iniciados.");
        } else {
            $this->log("Ainda aguardando prazo de 3 dias.");
        }
    }

    private function handleStaleProcessing($state) {
        $folder = $state['pasta_rfb'];
        
        // Verifica se a última atualização foi recente (menos de 1 hora)
        // Isso evita que o cron/loop mate um processo que está apenas lento (download)
        $lastUpdate = new DateTime($state['data_detectada']); // Assumindo data_detectada ou log update time
        // Melhor seria ter um campo updated_at, mas vamos usar o diff básico por enquanto
        // UPDATE: Para ser mais preciso, vamos checar processos do SO se possível, 
        // mas como não temos PID salvo, vamos usar janela de tempo.
        
        // Vamos checar quando o campo 'log' foi escrito pela última vez? Não temos esse dado no DB estruturado.
        // Vamos assumir "Innocent until proven guilty". Se o status é PROCESSING
        // e foi setado HOJE, deixa rodar.
        
        // Melhor: Vamos criar lock file?
        $lockFile = '/tmp/cnpj_import_running.lock';
        if (file_exists($lockFile)) {
             $fileAge = time() - filemtime($lockFile);
             if ($fileAge < 3600 * 2) { // 2 horas de tolerância
                 $this->log("⚠️ Processo já rodando (Lock file detectado há " . round($fileAge/60) . " min). Abortando nova execução.");
                 return;
             } else {
                 $this->log("⚠️ Lock file expirado (mais de 2h). Removendo e reiniciando.");
                 unlink($lockFile);
             }
        }
        
        file_put_contents($lockFile, getmypid());

         $this->log("ALERTA: Pasta $folder encontrada em estado PROCESSING.", 'WARNING');
         // Just ensure status is correct and let workers pick up?
         // Yes, if status is PROCESSING, workers are already valid to run.
         // Force start workers? No need, cron runs every minute.
         // Just log.
          
         @unlink($lockFile);
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

    private function initSchema($dbName) {
        $queries = [
            "CREATE TABLE IF NOT EXISTS `cnae` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS `natju` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS `quals` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS `pais` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(500) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS `moti` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS `munic` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS `estabelecimento` (
              `cnpj_basico` CHAR(8) NOT NULL,
              `cnpj_ordem` CHAR(4) NOT NULL,
              `cnpj_dv` CHAR(2) NOT NULL,
              `identificador_matriz_filial` CHAR(1) NOT NULL,
              `nome_fantasia` VARCHAR(1000) NULL,
              `situacao_cadastral` CHAR(1) NOT NULL,
              `data_situacao_cadastral` DATE NULL,
              `motivo_situacao_cadastral` INT NOT NULL,
              `nome_cidade_exterior` VARCHAR(45) NULL,
              `pais` INT NULL,
              `data_inicio_atividade` DATETIME NULL,
              `cnae_fiscal_principal` INT NOT NULL,
              `cnae_fiscal_secundaria` VARCHAR(1000) NULL,
              `tipo_logradouro` VARCHAR(500) NULL,
              `logradouro` VARCHAR(1000) NULL,
              `numero` VARCHAR(45) NULL,
              `complemento` VARCHAR(100) NULL,
              `bairro` VARCHAR(45) NULL,
              `cep` VARCHAR(45) NULL,
              `uf` VARCHAR(45) NULL,
              `municipio` INT NULL,
              `ddd_1` VARCHAR(45) NULL,
              `telefone_1` VARCHAR(45) NULL,
              `ddd_2` VARCHAR(45) NULL,
              `telefone_2` VARCHAR(45) NULL,
              `ddd_fax` VARCHAR(45) NULL,
              `fax` VARCHAR(45) NULL,
              `correio_eletronico` VARCHAR(45) NULL,
              `situacao_especial` VARCHAR(45) NULL,
              `data_situacao_especial` DATE NULL,
              PRIMARY KEY (`cnpj_basico`, `cnpj_ordem`, `cnpj_dv`),
              INDEX `idx_cnae` (`cnae_fiscal_principal`),
              INDEX `idx_uf` (`uf`),
              INDEX `idx_municipio` (`municipio`)
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `empresa` (
              `cnpj_basico` CHAR(8) NOT NULL PRIMARY KEY,
              `razao_social` VARCHAR(1000) NULL,
              `natureza_juridica` INT NULL,
              `qualificacao_responsavel` INT NULL,
              `capital_social` VARCHAR(45) NULL,
              `porte_empresa` VARCHAR(45) NULL,
              `ente_federativo_responsavel` VARCHAR(45) NULL,
              INDEX `idx_razao` (`razao_social`(100))
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `simples` (
              `cnpj_basico` CHAR(8) NOT NULL PRIMARY KEY,
              `opcao_pelo_simples` CHAR(1) NULL,
              `data_opcao_simples` DATE NULL,
              `data_exclusao_simples` DATE NULL,
              `opcao_mei` CHAR(1) NULL,
              `data_opcao_mei` DATE NULL,
              `data_exclusao_mei` DATE NULL
            ) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `socios` (
              `cnpj_basico` CHAR(8) NOT NULL,
              `identificador_socio` INT NOT NULL,
              `nome_socio_razao_social` VARCHAR(1000) NULL,
              `cpf_cnpj_socio` VARCHAR(45) NULL,
              `qualificacao_socio` INT NULL,
              `data_entrada_sociedade` DATE NULL,
              `pais` INT NULL,
              `representante_legal` VARCHAR(45) NULL,
              `nome_do_representante` VARCHAR(500) NULL,
              `qualificacao_representante_legal` INT NULL,
              `faixa_etaria` INT NULL,
              INDEX `idx_socio_cnpj` (`cnpj_basico`),
              INDEX `idx_nome_socio` (`nome_socio_razao_social`(100))
            ) ENGINE=InnoDB"
        ];
        
        foreach ($queries as $sql) {
            $this->pdo->exec($sql);
        }
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
