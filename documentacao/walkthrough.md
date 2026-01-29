# Walkthrough - Otimização do Projeto CNPJ

Concluí a otimização do projeto CNPJ e a preparação para deploy no Portainer.

## Mudanças Implementadas

### 1. Otimização de Performance
-   **Inserções em Lote**: refatorei `UploadCsv.class.php` para usar transações de banco de dados, comitando a cada 10.000 registros. Isso substitui a estratégia lenta de commit linha-a-linha.
-   **TPDOConnection**: Adicionei métodos estáticos (`beginTransaction`, `commit`, `rollBack`) para suportar a nova lógica de lotes.
-   **Configuração PHP**: Criei `php-custom.ini` para aumentar limites de memória (2GB) e tempo de execução (ilimitado) para tarefas pesadas de importação.

### 2. Infraestrutura (Portainer/Docker)
-   **Stack Portainer**: Criei `portainer-stack.yml` com:
    -   `apache_php`: Container da aplicação com config PHP customizada montada.
    -   `mariadb`: Banco de dados com tuning de performance (`innodb_buffer_pool_size=1G`).
    -   `adminer` & `phpmyadmin`: Ferramentas para gerenciamento do banco.
    -   **Volumes**: Volume dedicado `extracted_files` para manipulação eficiente de arquivos.

## Verificação

### Verificação de Arquivos
Verifiquei que os seguintes arquivos foram criados/modificados corretamente:
-   `portainer-stack.yml`
-   `php-custom.ini`
-   `www/cargabd/controllers/UploadCsv.class.php`
-   `www/cargabd/controllers/TPDOConnection.class.php`

### Como Rodar
1.  Abra o Portainer ou um terminal.
2.  Execute `docker-compose -f portainer-stack.yml up -d`.
3.  Acesse a aplicação em `http://localhost:8081`.

O sistema está pronto para deploy e deve performar significativamente melhor durante a importação de dados.
