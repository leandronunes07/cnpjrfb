<?php
header('Content-Type: application/json');
ini_set('display_errors', 0); // Ensure no PHP warnings/errors corrupt the JSON output
require_once __DIR__ . '/../vendor/autoload.php';

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;

try {
    $pdo = Database::getInstance();
    $action = $_GET['action'] ?? 'stats'; // stats, logs, reset, cron_status, cron_install

    switch ($action) {
        case 'stats':
            // 1. Counters
            $stats = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) as errors,
                    SUM(CASE WHEN status IN ('IMPORTING', 'EXTRACTING', 'DOWNLOADING') THEN 1 ELSE 0 END) as active,
                    SUM(rows_processed) as total_rows
                FROM extractor_jobs
            ")->fetch(PDO::FETCH_ASSOC);

            // 2. Active Jobs (Detail) - Added limits to prevent huge json
            $activeJobs = $pdo->query("
                SELECT * FROM extractor_jobs 
                WHERE status IN ('DOWNLOADING', 'EXTRACTING', 'IMPORTING')
                ORDER BY updated_at DESC
                LIMIT 50 
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 3. Recent History
            $history = $pdo->query("
                SELECT * FROM extractor_jobs 
                ORDER BY updated_at DESC LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'ok',
                'timestamp' => date('Y-m-d H:i:s'),
                'metrics' => $stats,
                'active' => $activeJobs,
                'history' => $history
            ]);
            break;

        case 'logs':
            // Read last 100 lines from Database
            try {
                // Check if table exists first (might not exist if no logs yet)
                $tableExists = $pdo->query("SHOW TABLES LIKE 'system_logs'")->rowCount() > 0;
                
                if ($tableExists) {
                    $stmt = $pdo->query("
                        SELECT created_at, level, message, context 
                        FROM system_logs 
                        ORDER BY id DESC 
                        LIMIT 100
                    ");
                    $logs = [];
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        // Format to match text log look: "[DATE] LEVEL: Message {context}"
                        $ctx = $row['context'] !== '[]' ? ' ' . $row['context'] : '';
                        $logs[] = "[{$row['created_at']}] {$row['level']}: {$row['message']}{$ctx}";
                    }
                    echo json_encode(['status' => 'ok', 'logs' => $logs]);
                } else {
                     echo json_encode(['status' => 'ok', 'logs' => ['Aguardando primeiros logs... (Tabela será criada automaticamente)']]);
                }
            } catch (\Exception $e) {
                 echo json_encode(['status' => 'ok', 'logs' => ['Erro ao ler logs do banco: ' . $e->getMessage()]]);
            }
            break;

        case 'reset':
            // POST /api.php?action=reset&id=123
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Method not allowed");
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $jobId = $input['id'] ?? null;
            
            if (!$jobId) throw new Exception("Job ID required");
            
            $stmt = $pdo->prepare("UPDATE extractor_jobs SET status = 'PENDING', error_message = NULL, rows_processed = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$jobId]);
            
            echo json_encode(['status' => 'ok', 'message' => "Job #$jobId reiniciado."]);
            break;
            
        case 'cron_status':
            $output = shell_exec('crontab -l 2>/dev/null');
            $installed = ($output && strpos($output, 'pipeline:worker') !== false);
            
            // Check active processes
            // "ps aux" might vary by alpine/debian, but usually works. 
            // We look for "php" and "pipeline:worker"
            $procs = shell_exec("ps aux | grep 'pipeline:worker' | grep -v grep | wc -l");
            $runningCount = (int)$procs;

            echo json_encode([
                'status' => 'ok', 
                'installed' => $installed, 
                'running' => $runningCount,
                'raw' => $output
            ]);
            break;
            
        case 'cron_install':
             $logDir = __DIR__ . '/../../logs';
             if (!file_exists($logDir)) mkdir($logDir, 0777, true);
             
             // Capture critical ENV vars to inject into Cron
             // (Cron environment is often empty in Docker)
             $envs = [
                'DB_HOST' => getenv('DB_HOST'),
                'DB_PORT' => getenv('DB_PORT'),
                'DB_NAME' => getenv('DB_NAME'),
                'DB_USER' => getenv('DB_USER'),
                'DB_PASSWORD' => getenv('DB_PASSWORD'),
                'EXTRACTED_FILES_PATH' => getenv('EXTRACTED_FILES_PATH'),
                'DOWNLOAD_PATH' => getenv('DOWNLOAD_PATH')
             ];
             
             $envStr = "";
             foreach($envs as $k=>$v) {
                 if($v) $envStr .= "$k='$v' ";
             }
             
             // Path to CLI Runner
             $runnerPath = realpath(__DIR__ . '/../cli-runner.php');
             $phpBin = PHP_BINARY; // Uses the same PHP running the script
             
             $cmdPrefix = $envStr . $phpBin . " " . $runnerPath;

             // Schedule: 
             // 1. Worker (Every minute)
             // 2. Discovery (Only at 02:00 AM)
             $cronContent = "# Generated by CNPJ Extractor Dashboard\n";
             $cronContent .= "* * * * * $cmdPrefix pipeline:worker >> $logDir/worker.log 2>&1\n";
             $cronContent .= "0 2 * * * $cmdPrefix pipeline:discover >> $logDir/discovery.log 2>&1\n";
             
             try {
                 $tmp = tempnam(sys_get_temp_dir(), 'cron');
                 file_put_contents($tmp, $cronContent);
                 
                 $output = shell_exec("crontab $tmp 2>&1");
                 unlink($tmp);
                 
                 if ($output) {
                     // Crontab sometimes outputs text even on success, but usually empty.
                     // If it says "command not found", we handle it.
                     if (strpos($output, 'command not found') !== false) {
                         throw new Exception("Comando 'crontab' não encontrado.");
                     }
                 }
                 
                 echo json_encode(['status' => 'ok', 'message' => 'Cron instalado com sucesso! (Variáveis de ambiente injetadas)']);
             } catch (Exception $e) {
                 echo json_encode(['status' => 'error', 'message' => 'Falha ao instalar Cron: ' . $e->getMessage()]);
             }
             break;
             
        case 'run_discovery':
            $runnerPath = realpath(__DIR__ . '/../cli-runner.php');
            
            // Try explicit 'php' command if PHP_BINARY looks weird or empty
            $phpBin = PHP_BINARY;
            if (!$phpBin || !file_exists($phpBin)) {
                $phpBin = 'php';
            }
            
            if (!$runnerPath || !file_exists($runnerPath)) {
                echo json_encode(['status' => 'error', 'message' => "cli-runner.php not found at: " . __DIR__ . '/../cli-runner.php']);
                break;
            }

            // Force executable permission if possible (Linux/Docker)
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                @chmod($runnerPath, 0755); // Use @ to suppress warnings that might leak to output
            }

            // Force permissions check (Debug)
            $logDir = __DIR__ . '/../../logs';
            if (!is_writable($logDir)) {
                 $debugMsg = "Web user (" . get_current_user() . ") cannot write to $logDir";
            } else {
                 $debugMsg = "Web user can write to logs.";
            }

            $cmd = "$phpBin $runnerPath pipeline:discover 2>&1";
            
            // Execute
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            $outputStr = implode("\n", $output);
            
            if ($returnCode !== 0) {
                 echo json_encode([
                    'status' => 'error', 
                    'message' => "Execution failed with code $returnCode", 
                    'output' => $outputStr,
                    'command' => $cmd,
                    'debug' => $debugMsg
                 ]);
            } else {
                 echo json_encode([
                    'status' => 'ok', 
                    'message' => "Discovery executed.", 
                    'output' => $outputStr,
                    'command' => $cmd,
                    'debug' => $debugMsg
                 ]);
            }
            break;

        default:
            throw new Exception("Invalid action");
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
