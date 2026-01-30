<?php

namespace CnpjRfb\Services;

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use PDO;

class BlueGreenService
{
    private PDO $pdo;
    private SchemaService $schema;
    
    private array $dataTables = [
        'cnae', 'natju', 'quals', 'pais', 'moti', 'munic',
        'estabelecimento', 'empresa', 'simples', 'socios'
    ];

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->schema = new SchemaService();
    }

    public function prepareTempTables(): void
    {
        Logger::log("BlueGreen: Preparing Temp Tables...", 'info');
        
        // Use modified SchemaService with suffix
        $this->schema->ensureTablesExist('_temp');
        
        // Also ensure _old exists or cleanup
        // We generally cleanup _old before starting a new cycle
        $this->dropSuffix('_old');
        
        Logger::log("BlueGreen: Temp Tables Ready.", 'info');
    }

    public function performSwap(): bool
    {
        Logger::log("BlueGreen: Initiating SWAP Sequence...", 'warning');

        try {
            $this->pdo->beginTransaction();

            foreach ($this->dataTables as $table) {
                // Strategy: 
                // 1. Rename CURRENT to OLD (if exists)
                // 2. Rename TEMP to CURRENT
                
                // Note: MySQL requires explicit handling if table doesn't exist
                // Ideally we do this atomically, but PDO doesn't support RENAME DATABASE natively easily
                // So we do table by table.
                
                // Drop OLD first to be sure
                $this->pdo->exec("DROP TABLE IF EXISTS `{$table}_old`");
                
                // Checks if current exists
                $exists = $this->pdo->query("SHOW TABLES LIKE '$table'")->rowCount() > 0;
                
                if ($exists) {
                    $this->pdo->exec("RENAME TABLE `$table` TO `{$table}_old`");
                }
                
                // Rename Temp to Main
                $this->pdo->exec("RENAME TABLE `{$table}_temp` TO `$table`");
            }
            
            $this->pdo->commit();
            Logger::log("BlueGreen: SWAP COMPLETED SUCCESSFULLY.", 'success');
            return true;
            
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            Logger::log("BlueGreen SWAP FAILED: " . $e->getMessage(), 'critical');
            return false;
        }
    }

    public function dropSuffix(string $suffix): void
    {
        foreach ($this->dataTables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$table}{$suffix}`");
        }
    }
}
