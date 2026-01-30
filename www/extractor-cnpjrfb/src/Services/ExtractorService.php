<?php

namespace CnpjRfb\Services;

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use PDO;
use ZipArchive;

class ExtractorService
{
    private PDO $pdo;
    private string $extractDir;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->extractDir = getenv('EXTRACTED_FILES_PATH') ?: __DIR__ . '/../../extracted';
        
        if (!is_dir($this->extractDir)) {
            mkdir($this->extractDir, 0777, true);
        }
    }

    public function extract(string $zipPath, int $jobId): ?string
    {
        if (!file_exists($zipPath)) {
            Logger::log("Zip file not found: $zipPath", 'error');
            $this->updateJobStatus($jobId, 'ERROR', 'File not found');
            return null;
        }

        $this->updateJobStatus($jobId, 'EXTRACTING');
        Logger::log("Starting extraction for $zipPath", 'info');

        $zip = new ZipArchive;
        if ($zip->open($zipPath) === TRUE) {
            $extractedFile = null;
            
            // Assume 1 file per zip usually in CNPJ
            if ($zip->numFiles > 0) {
                $extractedFile = $zip->getNameIndex(0);
            }

            $zip->extractTo($this->extractDir);
            $zip->close();
            
            if ($extractedFile) {
                $fullPath = $this->extractDir . DIRECTORY_SEPARATOR . $extractedFile;
                Logger::log("Extraction success: $fullPath", 'info');
                
                // Update DB with extracted filename if needed or just status
                $this->updateJobStatus($jobId, 'EXTRACTED');
                return $fullPath;
            }
        } else {
            Logger::log("Failed to open zip: $zipPath", 'error');
            $this->updateJobStatus($jobId, 'ERROR', 'Invalid Zip');
            return null;
        }
        
        return null;
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
