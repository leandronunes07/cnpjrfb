-- Database Schema 2.0 (Optimized for Bulk Load)
-- Engine: InnoDB (ROW_FORMAT=DYNAMIC)
-- Encoding: utf8mb4

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

-- -----------------------------------------------------
-- JOB CONTROL
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `extractor_jobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `file_name` VARCHAR(255) NOT NULL,
    `file_size` BIGINT NULL,
    `type` ENUM('EMPRESA', 'ESTABELECIMENTO', 'SOCIO', 'SIMPLES', 'CNAE', 'MOTI', 'MUNIC', 'NATJU', 'PAIS', 'QUALS') NOT NULL,
    `status` ENUM('PENDING', 'DOWNLOADING', 'DOWNLOADED', 'EXTRACTING', 'EXTRACTED', 'IMPORTING', 'COMPLETED', 'ERROR') DEFAULT 'PENDING',
    `rows_processed` INT DEFAULT 0,
    `error_message` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_jobs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------
-- AUXILIARY TABLES
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `cnae` (
  `codigo` INT NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `natju` (
  `codigo` INT NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `quals` (
  `codigo` INT NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `pais` (
  `codigo` INT NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `moti` (
  `codigo` INT NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `munic` (
  `codigo` INT NOT NULL,
  `descricao` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- MAIN TABLES
-- -----------------------------------------------------

-- EMPRESA (Dados Básicos)
CREATE TABLE IF NOT EXISTS `empresa` (
  `cnpj_basico` CHAR(8) NOT NULL, 
  `razao_social` VARCHAR(150) NULL, -- Reduzido de 1000 para 150 (suficiente para Razão Social)
  `natureza_juridica` SMALLINT NULL,
  `qualificacao_responsavel` SMALLINT NULL,
  `capital_social` DECIMAL(15,2) NULL, -- Alterado de VARCHAR para DECIMAL
  `porte_empresa` TINYINT NULL,
  `ente_federativo_responsavel` VARCHAR(50) NULL,
  
  PRIMARY KEY (`cnpj_basico`),
  INDEX `idx_empresa_razao` (`razao_social`), -- Index para busca por nome
  INDEX `idx_empresa_natju` (`natureza_juridica`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ESTABELECIMENTO (Onde o CNPJ completo vive)
CREATE TABLE IF NOT EXISTS `estabelecimento` (
  `cnpj_basico` CHAR(8) NOT NULL,
  `cnpj_ordem` CHAR(4) NOT NULL,
  `cnpj_dv` CHAR(2) NOT NULL,
  `identificador_matriz_filial` TINYINT NOT NULL,
  `nome_fantasia` VARCHAR(150) NULL,
  `situacao_cadastral` TINYINT NOT NULL,
  `data_situacao_cadastral` DATE NULL,
  `motivo_situacao_cadastral` SMALLINT NOT NULL,
  `nome_cidade_exterior` VARCHAR(45) NULL,
  `pais` SMALLINT NULL,
  `data_inicio_atividade` DATE NULL,
  `cnae_fiscal_principal` INT NOT NULL,
  `cnae_fiscal_secundaria` TEXT NULL, -- Lista separada por virgula
  `tipo_logradouro` VARCHAR(20) NULL,
  `logradouro` VARCHAR(100) NULL,
  `numero` VARCHAR(10) NULL,
  `complemento` VARCHAR(100) NULL,
  `bairro` VARCHAR(50) NULL,
  `cep` CHAR(8) NULL,
  `uf` CHAR(2) NULL,
  `municipio` INT NULL,
  `ddd_1` VARCHAR(4) NULL,
  `telefone_1` VARCHAR(8) NULL,
  `ddd_2` VARCHAR(4) NULL,
  `telefone_2` VARCHAR(8) NULL,
  `ddd_fax` VARCHAR(4) NULL,
  `fax` VARCHAR(8) NULL,
  `correio_eletronico` VARCHAR(100) NULL,
  `situacao_especial` VARCHAR(45) NULL,
  `data_situacao_especial` DATE NULL,
  
  -- PRIMARY KEY Composta (CNPJ Completo)
  PRIMARY KEY (`cnpj_basico`, `cnpj_ordem`, `cnpj_dv`),
  
  -- Índices Estratégicos
  INDEX `idx_estab_uf_mun` (`uf`, `municipio`),
  INDEX `idx_estab_cnae` (`cnae_fiscal_principal`),
  INDEX `idx_estab_nome_fantasia` (`nome_fantasia`),
  INDEX `idx_estab_situacao` (`situacao_cadastral`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SOCIOS
CREATE TABLE IF NOT EXISTS `socios` (
  `cnpj_basico` CHAR(8) NOT NULL,
  `identificador_socio` TINYINT NOT NULL,
  `nome_socio_razao_social` VARCHAR(150) NULL,
  `cpf_cnpj_socio` VARCHAR(14) NULL,
  `qualificacao_socio` SMALLINT NULL,
  `data_entrada_sociedade` DATE NULL,
  `pais` SMALLINT NULL,
  `representante_legal` VARCHAR(11) NULL,
  `nome_do_representante` VARCHAR(150) NULL,
  `qualificacao_representante_legal` SMALLINT NULL,
  `faixa_etaria` TINYINT NULL,
  
  INDEX `idx_socios_cnpj_basico` (`cnpj_basico`),
  INDEX `idx_socios_documento` (`cpf_cnpj_socio`),
  INDEX `idx_socios_nome` (`nome_socio_razao_social`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SIMPLES
CREATE TABLE IF NOT EXISTS `simples` (
  `cnpj_basico` CHAR(8) NOT NULL,
  `opcao_pelo_simples` CHAR(1) NULL,
  `data_opcao_simples` DATE NULL,
  `data_exclusao_simples` DATE NULL,
  `opcao_mei` CHAR(1) NULL,
  `data_opcao_mei` DATE NULL,
  `data_exclusao_mei` DATE NULL,
  
  PRIMARY KEY (`cnpj_basico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
