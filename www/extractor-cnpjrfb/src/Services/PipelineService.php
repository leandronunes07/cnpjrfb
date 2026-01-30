<?php

namespace CnpjRfb\Services;

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use PDO;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class PipelineService
{
    private PDO $pdo;
    private BlueGreenService $blueGreen;
    private NotificationService $notifier;
    
    // Using reliable IP address instead of domain which often has DNS/SSL issues
    private const BASE_URL = 'http://200.152.38.155/CNPJ/';

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->blueGreen = new BlueGreenService();
        $this->notifier = new NotificationService();
    }

    /**
     * 1. Discovery Phase: Scrapes RFB page and populates `extractor_jobs`
     */
    public function discoverFiles(): void
    {
        Logger::log("Starting Discovery Phase using Guzzle...", 'info');
        
        try {
            $client = new Client([
                'verify' => false, // Skip SSL verification for RFB
                'timeout' => 60,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);
            
            try {
                $response = $client->get(self::BASE_URL);
                $html = (string) $response->getBody();
            } catch (RequestException $e) {
                Logger::log("Guzzle Connection Error: " . $e->getMessage(), 'error');
                return;
            }
            
            // Look for date folders (YYYY-MM)
            preg_match_all('/href="([0-9]{4}-[0-9]{2})\/?"/i', $html, $dirMatches);
            
            if (!empty($dirMatches[1])) {
                $dirs = array_unique($dirMatches[1]);
                rsort($dirs); // Newest first
                $latestDir = $dirs[0];
                
                Logger::log("Found date subdirectories. Latest: $latestDir", 'info');
                
                // Check if we already have this version
                $stmt = $this->pdo->prepare("SELECT status FROM rfb_versions WHERE version_folder = ?");
                $stmt->execute([$latestDir]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing) {
                    // NEW VERSION!
                    $this->notifier->send("Nova Versão Detectada: $latestDir", "Iniciando processo de Carga para $latestDir.");
                    
                    // Register
                    $stmt = $this->pdo->prepare("INSERT INTO rfb_versions (version_folder, base_url, status) VALUES (?, ?, 'DISCOVERED')");
                    $stmt->execute([$latestDir, self::BASE_URL . $latestDir . '/']);
                    
                    // Create Temp Tables
                    $this->blueGreen->prepareTempTables();
                    
                    // Fetch Files
                    $subUrl = self::BASE_URL . $latestDir . '/';
                    try {
                        $subResponse = $client->get($subUrl);
                        $htmlSub = (string) $subResponse->getBody();
                    } catch (RequestException $e) {
                         Logger::log("Failed to fetch sub-directory $subUrl: " . $e->getMessage(), 'error');
                         return;
                    }
                    
                    preg_match_all('/href="([^"]+\.zip)"/i', $htmlSub, $subMatches);
                    
                    if (!empty($subMatches[1])) {
                        $this->processZipFiles(array_unique($subMatches[1]), $subUrl);
                        
                        // Update status to PROCESSING so workers can start
                        $this->pdo->prepare("UPDATE rfb_versions SET status='PROCESSING', total_files=? WHERE version_folder=?")
                             ->execute([count(array_unique($subMatches[1])), $latestDir]);
                    }
                } elseif ($existing['status'] == 'DISCOVERED') {
                     // Retry logic if needed
                } else {
                     Logger::log("Version $latestDir already tracked (Status: {$existing['status']}).", 'info');
                }
            } else {
                Logger::log("No date folders found on RFB page.", 'warning');
            }
        } catch (Exception $e) {
            Logger::log("Discovery Failed Logic: " . $e->getMessage(), 'error');
        }
    }
    
    private function processZipFiles(array $files, string $baseUrl): void
    {
        $count = 0;
        foreach ($files as $file) {
            $file = basename($file);
            $type = $this->determineType($file);
            if (!$type) continue; 

            $stmt = $this->pdo->prepare("SELECT id FROM extractor_jobs WHERE file_name = ?");
            $stmt->execute([$file]);
            
            if (!$stmt->fetch()) {
                $insert = $this->pdo->prepare("INSERT INTO extractor_jobs (file_name, type, status, created_at) VALUES (?, ?, 'PENDING', NOW())");
                $insert->execute([$file, $type]);
                $count++;
            }
        }
        Logger::log("Discovery Complete. New files added: $count", 'info');
    }

    /**
     * 2. Worker Phase
     */
    public function processNext(): void
    {
        // Check active version
        $stmt = $this->pdo->query("SELECT version_folder FROM rfb_versions WHERE status = 'PROCESSING' ORDER BY id DESC LIMIT 1");
        $activeVersion = $stmt->fetchColumn();
        
        if (!$activeVersion) {
            // Logger::log("No active version in PROCESSING state.", 'debug');
            return;
        }

        // 1. IMPORT (Priority High - Frees disk)
        $job = $this->findJobByStatus('EXTRACTED');
        if ($job) {
            $this->runImport($job);
            $this->checkVersionCompletion($activeVersion);
            return;
        }

        // 2. EXTRACT
        $job = $this->findJobByStatus('DOWNLOADED');
        if ($job) {
            $this->runExtract($job);
            return;
        }

        // 3. DOWNLOAD
        // Backpressure check: Don't download if too many are extracted/extracting (disk usage)
        if ($this->countJobsByStatus(['EXTRACTED', 'IMPORTING']) < 3) {
            $job = $this->findJobByStatus('PENDING');
            if ($job) {
                $this->runDownload($job);
                return;
            }
        }
    }
    
    public function finalizeSwap(): void
    {
        // Find version waiting validation
        $stmt = $this->pdo->query("SELECT version_folder FROM rfb_versions WHERE status = 'WAITING_VALIDATION' ORDER BY id DESC LIMIT 1");
        $version = $stmt->fetchColumn();
        
        if (!$version) {
            Logger::log("No version waiting validation.", 'warning');
            return;
        }
        
        if ($this->blueGreen->performSwap()) {
            $this->pdo->prepare("UPDATE rfb_versions SET status='COMPLETED', completed_at=NOW() WHERE version_folder=?")
                 ->execute([$version]);
                 
            $this->notifier->send("✅ Deploy Finalizado ($version)", "O banco de produção foi atualizado com a versão $version.");
            
            // Cleanup Old Tables?
            // $this->blueGreen->dropSuffix('_old');
        }
    }

    private function findJobByStatus(string $status): ?array
    {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("SELECT * FROM extractor_jobs WHERE status = ? LIMIT 1 FOR UPDATE SKIP LOCKED");
            $stmt->execute([$status]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->pdo->commit();
            return $job ?: null;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return null;
        }
    }
    
    private function countJobsByStatus(array $statuses): int {
        $in = str_repeat('?,', count($statuses) - 1) . '?';
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM extractor_jobs WHERE status IN ($in)");
        $stmt->execute($statuses);
        return $stmt->fetchColumn();
    }

    private function runDownload(array $job): void
    {
        $downloader = new DownloaderService();
        $stmt = $this->pdo->query("SELECT base_url FROM rfb_versions WHERE status='PROCESSING' LIMIT 1");
        $base = $stmt->fetchColumn() ?: self::BASE_URL;
        
        $url = $base . $job['file_name'];
        Logger::log("Worker: Downloading {$job['file_name']}", 'info');
        $downloader->download($url, $job['file_name'], $job['id']);
    }

    private function runExtract(array $job): void
    {
        $extractor = new ExtractorService();
        $downloadPath = (getenv('DOWNLOAD_PATH') ?: __DIR__ . '/../../downloads') . '/' . $job['file_name'];
        Logger::log("Worker: Extracting Job #{$job['id']}", 'info');
        $extractor->extract($downloadPath, $job['id']);
    }

    private function runImport(array $job): void
    {
        $importer = new BulkImporterService();
        $extractDir = getenv('EXTRACTED_FILES_PATH') ?: __DIR__ . '/../../extracted';
        
        $baseName = pathinfo($job['file_name'], PATHINFO_FILENAME);
        $candidates = glob("$extractDir/*$baseName*");
        
        if (empty($candidates)) {
             Logger::log("Worker: Extracted file not found for {$job['file_name']}", 'error');
             // Consider marking as ERROR or re-extract?
             return;
        }
        
        $targetFile = $candidates[0];
        
        Logger::log("Worker: Importing Job #{$job['id']} into _temp", 'info');
        
        // Pass '_temp' suffix!
        $success = $importer->import($targetFile, $job['type'], $job['id'], '_temp');
        
        if ($success) {
            @unlink($targetFile);
            @unlink((getenv('DOWNLOAD_PATH') ?: __DIR__ . '/../../downloads') . '/' . $job['file_name']);
        }
    }
    
    private function checkVersionCompletion(string $version): void
    {
        // Check if any job is not COMPLETED
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM extractor_jobs WHERE status != 'COMPLETED'");
        $pending = $stmt->fetchColumn();
        
        if ($pending == 0) {
            Logger::log("All jobs completed for $version. Initiating Validation/Swap flow.", 'success');
            
            // Mark version as WAITING_VALIDATION
            $this->pdo->prepare("UPDATE rfb_versions SET status='WAITING_VALIDATION', completed_at=NOW() WHERE version_folder=?")
                 ->execute([$version]);
                 
            // Send Notification
            $this->notifier->send(
                "✅ Carga $version Concluída (Temp)", 
                "Todos os arquivos foram importados para as tabelas _temp.<br>Execute 'php cli-runner.php pipeline:finalize' para realizar o SWAP."
            );
        }
    }

    private function determineType(string $filename): ?string
    {
        if (stripos($filename, 'EMPRE') !== false) return 'EMPRESA';
        if (stripos($filename, 'ESTABELE') !== false) return 'ESTABELECIMENTO';
        if (stripos($filename, 'SOCIO') !== false) return 'SOCIO';
        if (stripos($filename, 'SIMPLES') !== false) return 'SIMPLES';
        if (stripos($filename, 'CNAE') !== false) return 'CNAE';
        if (stripos($filename, 'MOTI') !== false) return 'MOTI';
        if (stripos($filename, 'MUNIC') !== false) return 'MUNIC';
        if (stripos($filename, 'NATJU') !== false) return 'NATJU';
        if (stripos($filename, 'PAIS') !== false) return 'PAIS';
        if (stripos($filename, 'QUAL') !== false) return 'QUALS';
        return null;
    }
}
