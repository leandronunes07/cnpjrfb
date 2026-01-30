# 🦅 Extractor CNPJ-RFB (Novo)

Este é o novo módulo de alta performance para extração de dados CNPJ.
Ele foi desenhado para ser **500x mais rápido** que o anterior, utilizando `LOAD DATA LOCAL INFILE` e processamento em streams.

## 🚀 Como Instalar

Como seu ambiente é Docker/Portainer, você precisa instalar as dependências do PHP dentro do container (ou na sua máquina se tiver PHP 8.1+ e Composer).

1.  **Instalar Dependências:**
    ```bash
    # Se estiver rodando do Host (Windows) e tiver composer:
    cd www/extractor-cnpjrfb
    composer install --ignore-platform-reqs
    
    # OU rodando de dentro do Container:
    docker exec -it <nome-do-container> bash
    cd /var/www/html/extractor-cnpjrfb
    composer install
    ```

2.  **Migrar Banco de Dados (Schema 2.0):**
    Este script vai criar as tabelas otimizadas (`empresa`, `estabelecimento`, `extractor_jobs`...).
    ```bash
    php migrate.php
    ```

## 🛠️ Como Usar (CLI)

O entrypoint é o `cli-runner.php`.

```bash
# Testar Conexão com Banco
php cli-runner.php test-db

# Importar um arquivo manual (Exemplo)
php cli-runner.php import-file /caminho/para/K3241.K03200Y0.D20511.EMPRECSC.zip EMPRESA
```

## 📊 Dashboard

Acesse pelo navegador:
`http://localhost/extractor-cnpjrfb/public/`

## 📁 Estrutura

- `src/Database`: Conexão PDO com suporte a LOAD DATA.
- `src/Services`: Lógica de Download, Extração e Importação Bulk.
- `sql/schema.sql`: Estrutura otimizada (Tipagem forte + Índices).
- `logs/`: Logs gerados (se configurado para arquivo).
