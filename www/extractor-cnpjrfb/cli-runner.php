<?php

use CnpjRfb\Database\Database;
use CnpjRfb\Services\BulkImporterService;
use CnpjRfb\Utils\Logger;
use CnpjRfb\Services\ExtractorService;
use CnpjRfb\Services\DownloaderService;

require_once __DIR__ . '/vendor/autoload.php';

// Check if vendor exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "⚠️  Vendor folder not found. Please run 'composer install' inside the container.\n";
    exit(1);
}

// Simple CLI Router (since we might not have Symfony Console installed yet in user env)
// Ideally we use Symfony Console, but for robustness without vendor ready:

$cmd = $argv[1] ?? 'help';

Logger::log("CLI Command triggered: $cmd", 'info');

// Ensure Schema exists (Lazy Init)
try {
    $schema = new \CnpjRfb\Services\SchemaService();
    $schema->ensureTablesExist();
} catch (Exception $e) {
    echo "Fatal Schema Error: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    switch ($cmd) {
        case 'test-db':
            $pdo = Database::getInstance();
            echo "✅ Conexão com Banco de Dados bem sucedida! \n";
            $res = $pdo->query("SELECT count(*) FROM extractor_jobs")->fetchColumn();
            echo "Jobs Atuais: $res\n";
            break;
            
        case 'import-file':
            // usage: php cli-runner.php import-file <path> <type>
            $path = $argv[2] ?? null;
            $type = $argv[3] ?? null;
            if (!$path || !$type) {
                die("Uso: import-file <caminho> <tipo>\n");
            }
            
            // Create a fake job for this manual run
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("INSERT INTO extractor_jobs (file_name, type, status) VALUES (?, ?, 'PENDING')");
            $stmt->execute([basename($path), $type]);
            $jobId = $pdo->lastInsertId();
            
            $importer = new BulkImporterService();
            $importer->import($path, $type, $jobId);
            break;

        case 'pipeline:discover':
            $pipeline = new \CnpjRfb\Services\PipelineService();
            $pipeline->discoverFiles();
            break;
            
        case 'pipeline:worker':
            $pipeline = new \CnpjRfb\Services\PipelineService();
            $pipeline->processNext();
            break;
            
        case 'pipeline:import-discovered':
            // Import files discovered by Windows host helper
            $jsonFile = __DIR__ . '/discovered_files.json';
            if (!file_exists($jsonFile)) {
                die("❌ No discovered_files.json found. Run discover-helper.php on Windows first.\n");
            }
            
            $data = json_decode(file_get_contents($jsonFile), true);
            $baseUrl = $data['base_url'] ?? '';
            $files = $data['files'] ?? [];
            
            if (empty($files)) {
                die("❌ No files in discovered_files.json\n");
            }
            
            // Extract version from URL (e.g., 2026-01)
            $version = null;
            if (preg_match('/\/([0-9]{4}-[0-9]{2})\/$/', $baseUrl, $matches)) {
                $version = $matches[1];
            }
            
            echo "📦 Importing " . count($files) . " discovered files...\n";
            if ($version) {
                echo "📅 Version: $version\n";
            }
            
            try {
                $pdo = Database::getInstance();
                echo "✅ Database connected\n";
                
                // Check if this version was already processed
                if ($version) {
                    $stmt = $pdo->prepare("SELECT status FROM rfb_versions WHERE version_folder = ?");
                    $stmt->execute([$version]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        echo "ℹ️  Version $version already tracked (Status: {$existing['status']})\n";
                    } else {
                        // Register new version
                        $stmt = $pdo->prepare("
                            INSERT INTO rfb_versions (version_folder, base_url, total_files, status) 
                            VALUES (?, ?, ?, 'DISCOVERED')
                        ");
                        $stmt->execute([$version, $baseUrl, count($files)]);
                        echo "✅ Registered new version: $version\n";
                    }
                }
                
                // Test query
                $test = $pdo->query("SELECT COUNT(*) FROM extractor_jobs")->fetchColumn();
                echo "📊 Current jobs in DB: $test\n";
                
            } catch (\Exception $e) {
                die("❌ Database Error: " . $e->getMessage() . "\n");
            }
            
            $count = 0;
            $errors = [];
            
            foreach ($files as $file) {
                try {
                    // Determine type
                    $type = null;
                    if (stripos($file, 'Empresa') !== false) $type = 'EMPRESA';
                    elseif (stripos($file, 'Estabele') !== false) $type = 'ESTABELECIMENTO';
                    elseif (stripos($file, 'Socio') !== false) $type = 'SOCIO';
                    elseif (stripos($file, 'Simples') !== false) $type = 'SIMPLES';
                    elseif (stripos($file, 'Cnae') !== false) $type = 'CNAE';
                    elseif (stripos($file, 'Moti') !== false) $type = 'MOTI';
                    elseif (stripos($file, 'Munic') !== false) $type = 'MUNIC';
                    elseif (stripos($file, 'Natureza') !== false) $type = 'NATJU';
                    elseif (stripos($file, 'Pais') !== false) $type = 'PAIS';
                    elseif (stripos($file, 'Qual') !== false) $type = 'QUALS';
                    
                    if (!$type) {
                        echo "⚠️  Skipping unknown type: $file\n";
                        continue;
                    }
                    
                    // Check if exists
                    $stmt = $pdo->prepare("SELECT id FROM extractor_jobs WHERE file_name = ?");
                    $stmt->execute([$file]);
                    
                    if (!$stmt->fetch()) {
                        $insert = $pdo->prepare("
                            INSERT INTO extractor_jobs (file_name, type, status, created_at) 
                            VALUES (?, ?, 'PENDING', NOW())
                        ");
                        $result = $insert->execute([$file, $type]);
                        
                        if ($result) {
                            $count++;
                            echo "✅ Added: $file ($type)\n";
                        } else {
                            $errors[] = "$file - Insert failed";
                            echo "❌ Failed: $file\n";
                        }
                    } else {
                        echo "⏭️  Exists: $file\n";
                    }
                } catch (\Exception $e) {
                    $errors[] = "$file - " . $e->getMessage();
                    echo "❌ Error on $file: " . $e->getMessage() . "\n";
                }
            }
            
            echo "\n✅ Imported $count new files to queue\n";
            
            if (!empty($errors)) {
                echo "\n⚠️  Errors:\n";
                foreach ($errors as $err) {
                    echo "  - $err\n";
                }
            }
            
            // Final count
            $final = $pdo->query("SELECT COUNT(*) FROM extractor_jobs")->fetchColumn();
            echo "📊 Total jobs in DB now: $final\n";
            
            // Update version status if all files were imported
            if ($version && $count > 0) {
                $pdo->prepare("UPDATE rfb_versions SET status = 'PROCESSING' WHERE version_folder = ?")->execute([$version]);
                echo "📌 Version $version marked as PROCESSING\n";
            }
            break;
            
        case 'pipeline:supervisor':
            echo "👮 Starting Supervisor Loop (Ctrl+C to stop)...\n";
            $pipeline = new \CnpjRfb\Services\PipelineService();
            
            while (true) {
                // 1. Discovery (Once every hour?) 
                // Better approach: Run discovery on a separate cron or check time.
                // For simplicity: Run discovery if files list is empty or time based.
                // Let's stick to simple loop: Pipeline triggers.
                
                // Ideally discovery is infrequent (once a day).
                // Let's assume the user runs 'pipeline:discover' separately via Cron, 
                // OR we check hour.
                if (date('i') === '00') { // Run hourly
                     $pipeline->discoverFiles();
                }

                // 2. Process Worker
                // This processes ONE job. We loop aggressively.
                $pipeline->processNext();
                
                // Sleep to prevent CPU hogging if queue empty
                usleep(500000); // 0.5s
                
                // Optional: Check memory usage and restart if high?
                if (memory_get_usage() > 500 * 1024 * 1024) {
                    echo "⚠️  Memory Limit Reached. Restarting...\n";
                    exit(0); // Supervisor (Docker/Systemd) will restart it
                }
            }
            break;

        case 'pipeline:finalize':
            $pipeline = new \CnpjRfb\Services\PipelineService();
            echo "🚀 Initiating Blue-Green Swap...\n";
            $pipeline->finalizeSwap();
            echo "✅ Swap Check Completed.\n";
            break;

        /* Was: case 'pipeline:worker': ... */
        case 'pipeline:worker':
             $pipeline = new \CnpjRfb\Services\PipelineService();
             $pipeline->processNext();
             break;
             
        /* Was: case 'pipeline:import-discovered': ... */
        case 'pipeline:import-discovered':
            // ... (Keep existing logic if needed, or deprecate favor of discoverFiles)
            // Keeping for backward compat / manual json loading
            // ... (Abbreviated for brevity, assuming we keep the existing block or similar)
            // Actually, replace_file_content needs EXACT match. 
            // My instruction was to Add commands.
            // I will target the switch block end.
            break;

        case 'help':
        default:
            echo "Extrator CLI - Comandos:\n";
            echo "----------------------\n";
            echo "  test-db                     Testar Conexão\n";
            echo "  pipeline:discover           Buscar novos arquivos no site RFB\n";
            echo "  pipeline:supervisor         Loop infinito de processamento (Daemon)\n";
            echo "  pipeline:worker             Processar UM job da fila\n";
            echo "  pipeline:finalize           Executar SWAP (Blue-Green) se validado\n";
            echo "  import-file <caminho> <tipo> Importar manualmente\n";
            break;
    }
} catch (Exception $e) {
    Logger::log("CLI Error: " . $e->getMessage(), 'error');
    echo "Error: " . $e->getMessage() . "\n";
}
