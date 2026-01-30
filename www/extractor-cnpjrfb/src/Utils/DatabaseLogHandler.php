<?php

namespace CnpjRfb\Utils;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use CnpjRfb\Database\Database;
use PDO;

class DatabaseLogHandler extends AbstractProcessingHandler
{
    private bool $initialized = false;

    protected function write(LogRecord $record): void
    {
        try {
            $pdo = Database::getInstance();
            
            // Auto-create table on first write (Lazy Migration)
            if (!$this->initialized) {
                $this->initializeTable($pdo);
                $this->initialized = true;
            }

            $stmt = $pdo->prepare("
                INSERT INTO system_logs (channel, level, message, context, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $record->channel,
                $record->level->name,
                $record->message,
                json_encode($record->context)
            ]);
            
        } catch (\Throwable $e) {
            // Fallback to error_log to avoid crash
            error_log("DB Logger Failed: " . $e->getMessage());
        }
    }

    private function initializeTable(PDO $pdo): void
    {
        // Simple schema for logs
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(50),
                level VARCHAR(20),
                message TEXT,
                context JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created (created_at DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}
