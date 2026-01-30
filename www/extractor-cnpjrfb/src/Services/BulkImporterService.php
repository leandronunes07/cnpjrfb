<?php

namespace CnpjRfb\Services;

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use PDO;
use PDOException;

class BulkImporterService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function import(string $filePath, string $type, int $jobId, string $tableSuffix = ''): bool
    {
        if (!file_exists($filePath)) {
            Logger::log("Import file not found: $filePath", 'error');
            $this->updateJobStatus($jobId, 'ERROR', 'File not found');
            return false;
        }

        $baseTableName = $this->getTableNameByType($type);
        if (!$baseTableName) {
            Logger::log("Unknown table for type: $type", 'error');
            $this->updateJobStatus($jobId, 'ERROR', "Unknown type $type");
            return false;
        }
        
        $tableName = $baseTableName . $tableSuffix;

        Logger::log("Starting Bulk Import for $type into $tableName...", 'info');
        $this->updateJobStatus($jobId, 'IMPORTING');

        try {
            // Optimization: Disable keys for MyISAM (not InnoDB, but still good practice to disable checks)
            // For InnoDB, unique checks and foreign key checks are the main things.
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->pdo->exec("SET UNIQUE_CHECKS = 0");
            $this->pdo->exec("SET SQL_LOG_BIN = 0"); 

            // Windows paths need escaping or forward slashes.
            $cleanPath = str_replace('\\', '/', $filePath);

            // Get Load Data Config (Columns & Set Clauses)
            $config = $this->getLoadDataConfig($type);
            $columnsClause = $config['columns'] ? " (" . implode(', ', $config['columns']) . ") " : "";
            $setClause = $config['set'] ? " SET " . implode(', ', $config['set']) : "";

            // NOTE: 'LOCAL' keyword is required for client-side file loading
            $sql = "LOAD DATA LOCAL INFILE '$cleanPath'
                    INTO TABLE `$tableName`
                    CHARACTER SET latin1
                    FIELDS TERMINATED BY ';'
                    ENCLOSED BY '\"'
                    LINES TERMINATED BY '\\n'
                    $columnsClause
                    $setClause";

            $this->pdo->exec($sql);
            
            // Re-enable checks
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            $this->pdo->exec("SET UNIQUE_CHECKS = 1");
            
            $rowCount = $this->pdo->query("SELECT ROW_COUNT()")->fetchColumn();
            
            Logger::log("Import success! Inserted $rowCount rows into $tableName", 'info');
            $this->updateJobStatus($jobId, 'COMPLETED', null, $rowCount);
            return true;

        } catch (PDOException $e) {
            Logger::log("Import Failed ($type): " . $e->getMessage(), 'error');
            $this->updateJobStatus($jobId, 'ERROR', $e->getMessage());
            return false;
        }
    }

    private function getTableNameByType(string $type): ?string
    {
        return match (strtoupper($type)) {
            'EMPRESA'           => 'empresa',
            'ESTABELECIMENTO'   => 'estabelecimento',
            'SOCIO'             => 'socios',
            'SIMPLES'           => 'simples',
            'CNAE'              => 'cnae',
            'MOTI'              => 'moti',
            'MUNIC'             => 'munic',
            'NATJU'             => 'natju',
            'PAIS'              => 'pais',
            'QUALS'             => 'quals',
            default => null
        };
    }
    
    // Defines column mapping and data sanitization for specific tables
    private function getLoadDataConfig(string $type): array
    {
        // Helper to generate date sanitization syntax
        // Transforms '00000000' or '' into NULL
        $sanitizeDate = fn($col, $var) => "$col = CASE WHEN $var IN ('00000000', '') THEN NULL ELSE STR_TO_DATE($var, '%Y%m%d') END";
        // Simple NULLIF for 00000000 (Legacy behavior was just empty string, but we want NULL for Date type)
        // Actually, if we use STR_TO_DATE, '00000000' fails. So we MUST case it.
        // Also, input might be already formatted? No, RFB CSV is raw YYYYMMDD.
        
        // Config Structure:
        // 'columns' => array of field names or @variables
        // 'set' => array of "col = val"
        
        switch (strtoupper($type)) {
            case 'ESTABELECIMENTO':
                return [
                    'columns' => [
                        'cnpj_basico', 'cnpj_ordem', 'cnpj_dv', 'identificador_matriz_filial', 
                        'nome_fantasia', 'situacao_cadastral', 
                        '@var_data_sit', // data_situacao_cadastral
                        'motivo_situacao_cadastral', 'nome_cidade_exterior', 'pais', 
                        '@var_data_inicio', // data_inicio_atividade
                        'cnae_fiscal_principal', 'cnae_fiscal_secundaria', 
                        'tipo_logradouro', 'logradouro', 'numero', 'complemento', 'bairro', 'cep', 'uf', 'municipio', 
                        'ddd_1', 'telefone_1', 'ddd_2', 'telefone_2', 'ddd_fax', 'fax', 'correio_eletronico', 
                        'situacao_especial', 
                        '@var_data_esp' // data_situacao_especial
                    ],
                    'set' => [
                        $sanitizeDate('data_situacao_cadastral', '@var_data_sit'),
                        $sanitizeDate('data_inicio_atividade', '@var_data_inicio'),
                        $sanitizeDate('data_situacao_especial', '@var_data_esp')
                    ]
                ];
                
            case 'SOCIOS':
                return [
                    'columns' => [
                        'cnpj_basico', 'identificador_socio', 'nome_socio_razao_social', 'cpf_cnpj_socio', 
                        'qualificacao_socio', 
                        '@var_data_entrada', // data_entrada_sociedade
                        'pais', 'representante_legal', 'nome_do_representante', 
                        'qualificacao_representante_legal', 'faixa_etaria'
                    ],
                    'set' => [
                        $sanitizeDate('data_entrada_sociedade', '@var_data_entrada')
                    ]
                ];
                
            case 'SIMPLES':
                return [
                    'columns' => [
                        'cnpj_basico', 'opcao_pelo_simples', 
                        '@var_dt_opt', // data_opcao_simples
                        '@var_dt_exc', // data_exclusao_simples
                        'opcao_mei', 
                        '@var_dt_opt_mei', // data_opcao_mei
                        '@var_dt_exc_mei' // data_exclusao_mei
                    ],
                    'set' => [
                        $sanitizeDate('data_opcao_simples', '@var_dt_opt'),
                        $sanitizeDate('data_exclusao_simples', '@var_dt_exc'),
                        $sanitizeDate('data_opcao_mei', '@var_dt_opt_mei'),
                        $sanitizeDate('data_exclusao_mei', '@var_dt_exc_mei')
                    ]
                ];
            
            // Tables without dates or special handling can fallback to default (empty = auto-map)
            // But if we want to be safe, we should map all.
            // EMPRESA has no dates.
            // CNAE, MOTI, MUNIC, etc are Key-Value.
            
            default:
                return ['columns' => [], 'set' => []];
        }
    }

    private function updateJobStatus(int $id, string $status, ?string $error = null, int $rows = 0): void
    {
        $sql = "UPDATE extractor_jobs SET status = :status, error_message = :error, rows_processed = :rows, updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':error' => $error,
            ':rows' => $rows,
            ':id' => $id
        ]);
    }
}
