<?php

namespace CnpjRfb\Services;

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class DownloaderService
{
    private Client $client;
    private PDO $pdo;
    private string $downloadDir;

    public function __construct()
    {
        $this->client = new Client([
            'timeout'  => 300,
            'verify'   => false, // CNPJ servers sometimes have SSL issues
        ]);
        
        $this->pdo = Database::getInstance();
        
        // Use path from ENV or default
        $this->downloadDir = getenv('DOWNLOAD_PATH') ?: __DIR__ . '/../../downloads';
        
        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0777, true);
        }
    }

    public function download(string $url, string $fileName, int $jobId): bool
    {
        $filePath = $this->downloadDir . DIRECTORY_SEPARATOR . $fileName;

        try {
            $this->updateJobStatus($jobId, 'DOWNLOADING');

            Logger::log("Downloading $fileName from $url", 'info');
            
            // Use Guzzle with sink to save directly to file (Low Memory Usage)
            $this->client->request('GET', $url, [
                'sink' => $filePath,
                'verify' => false,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            if (!file_exists($filePath) || filesize($filePath) < 100) {
                 throw new \Exception("File downloaded but seems empty/corrupted.");
            }

            $this->updateJobStatus($jobId, 'DOWNLOADED');
            Logger::log("Download complete: $fileName (" . filesize($filePath) . " bytes)", 'info');
            return true;

        } catch (\Exception $e) {
            Logger::log("Download error for $fileName: " . $e->getMessage(), 'error');
            $this->updateJobStatus($jobId, 'ERROR', $e->getMessage());
            return false;
        }
    }

    private function updateJobStatus(int $id, string $status, ?string $error = null): void
    {
        $sql = "UPDATE extractor_jobs SET status = :status, error_message = :error, updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':error' => $error,
            ':id' => $id
        ]);
    }
}
