<?php

namespace CnpjRfb\Services;

use CnpjRfb\Database\Database;
use CnpjRfb\Utils\Logger;
use PDO;
use PDOException;

class SchemaService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function ensureTablesExist(string $suffix = ''): void
    {
        Logger::log("Verifying Database Schema (Suffix: '$suffix')...", 'info');

        $s = $suffix; // Short alias

        $queries = [
            "CREATE TABLE IF NOT EXISTS `cnae{$s}` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `natju{$s}` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `quals{$s}` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `pais{$s}` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(500) NOT NULL) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `moti{$s}` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL) ENGINE=InnoDB",
            "CREATE TABLE IF NOT EXISTS `munic{$s}` (`codigo` INT NOT NULL PRIMARY KEY, `descricao` VARCHAR(1000) NOT NULL) ENGINE=InnoDB",
            
            "CREATE TABLE IF NOT EXISTS `estabelecimento{$s}` (
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
              INDEX `idx_cnae{$s}` (`cnae_fiscal_principal`),
              INDEX `idx_uf{$s}` (`uf`),
              INDEX `idx_municipio{$s}` (`municipio`)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC", 

            "CREATE TABLE IF NOT EXISTS `empresa{$s}` (
              `cnpj_basico` CHAR(8) NOT NULL PRIMARY KEY,
              `razao_social` VARCHAR(1000) NULL,
              `natureza_juridica` INT NULL,
              `qualificacao_responsavel` INT NULL,
              `capital_social` VARCHAR(45) NULL,
              `porte_empresa` VARCHAR(45) NULL,
              `ente_federativo_responsavel` VARCHAR(45) NULL,
              INDEX `idx_razao{$s}` (`razao_social`(100))
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC",

            "CREATE TABLE IF NOT EXISTS `simples{$s}` (
              `cnpj_basico` CHAR(8) NOT NULL PRIMARY KEY,
              `opcao_pelo_simples` CHAR(1) NULL,
              `data_opcao_simples` DATE NULL,
              `data_exclusao_simples` DATE NULL,
              `opcao_mei` CHAR(1) NULL,
              `data_opcao_mei` DATE NULL,
              `data_exclusao_mei` DATE NULL
            ) ENGINE=InnoDB",

            "CREATE TABLE IF NOT EXISTS `socios{$s}` (
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
              INDEX `idx_socio_cnpj{$s}` (`cnpj_basico`)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC",
            
             // Extractor Jobs Table (Control) - These are SYSTEM TABLES, NO SUFFIX
             "CREATE TABLE IF NOT EXISTS `extractor_jobs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `file_name` VARCHAR(255) NOT NULL UNIQUE,
                `type` VARCHAR(50) NOT NULL,
                `status` VARCHAR(20) DEFAULT 'PENDING',
                `error_message` TEXT NULL,
                `rows_processed` INT DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`)
             ) ENGINE=InnoDB",
             
             // Version Tracking Table - SYSTEM TABLE, NO SUFFIX
             "CREATE TABLE IF NOT EXISTS `rfb_versions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `version_folder` VARCHAR(20) NOT NULL UNIQUE COMMENT 'e.g., 2026-01',
                `base_url` VARCHAR(500) NOT NULL,
                `total_files` INT DEFAULT 0,
                `status` VARCHAR(20) DEFAULT 'DISCOVERED' COMMENT 'DISCOVERED, PROCESSING, COMPLETED',
                `discovered_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `completed_at` DATETIME NULL,
                INDEX `idx_version` (`version_folder`)
             ) ENGINE=InnoDB"
        ];

        foreach ($queries as $sql) {
            try {
                // Determine table name for logging
                if (preg_match('/TABLE IF NOT EXISTS `(\w+)/', $sql, $matches)) {
                    $table = $matches[1];
                } else {
                    $table = 'Unknown';
                }
                
                $this->pdo->exec($sql);
            } catch (PDOException $e) {
                Logger::log("Schema Error ($table): " . $e->getMessage(), 'critical');
                // Re-throw or die? For now log critical.
            }
        }
        
        Logger::log("Schema Verification Complete.", 'info');
    }
}
