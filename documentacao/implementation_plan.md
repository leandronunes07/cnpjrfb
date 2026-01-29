# Plano de Otimização e Deploy no Portainer

O projeto CNPJ sofria com problemas significativos de performance devido a inserções linha-a-linha durante a importação de dados e configurações padrão não otimizadas. Este plano abordou esses gargalos e preparou o projeto para deploy via Portainer.

## Revisão do Usuário Necessária

> [!IMPORTANT]
> **Estratégia de Importação de Dados**: A mudança envolveu encapsular as inserções linha-a-linha em transações de banco de dados. Esta é uma mudança segura que melhora drasticamente a velocidade (frequentemente 10-100x).

## Mudanças Realizadas

### Infraestrutura (Docker & Portainer)

#### [NOVO] [portainer-stack.yml](file:///c:/Projetos/Development/cnpj/portainer-stack.yml)
- Criado um `docker-compose.yml` pronto para produção e otimizado para Portainer.
- Redes explícitas e políticas de reinicialização definidas.
- **Otimização**: Volume `extracted_files` montado no MariaDB (preparado para futuro `LOAD DATA INFILE`).
- **Otimização**: Uso de `php.ini` personalizado montado via bind mount.

#### [NOVO] [php-custom.ini](file:///c:/Projetos/Development/cnpj/php-custom.ini)
- Aumento do `memory_limit` para `2G`.
- Aumento do `max_execution_time` para `0` (ilimitado) ou valor alto para CLI.
- Habilitação do `opcache` para performance.

### Lógica da Aplicação (Performance)

#### [MODIFICADO] [UploadCsv.class.php](file:///c:/Projetos/Development/cnpj/www/cargabd/controllers/UploadCsv.class.php)
- **Implementação de Lotes Transacionais**: Em vez de comitar cada linha (auto-commit padrão), inicia-se uma transação e comita-se a cada 10.000 linhas.
- **Lógica**:
    - `beginTransaction()` antes do loop.
    - Checagem `$numRegistros % 10000 == 0` dentro do loop -> `commit()` então `beginTransaction()`.
    - `commit()` após o loop.

#### [MODIFICADO] [TPDOConnection.class.php](file:///c:/Projetos/Development/cnpj/www/cargabd/controllers/TPDOConnection.class.php)
- Adicionados métodos auxiliares para gerenciamento de Transações.
    - `beginTransaction()`
    - `commit()`
    - `rollBack()`

## Plano de Verificação

### Testes Automatizados
- Rodar o script de importação com um arquivo de amostra pequeno.
- Verificar dados no banco usando `adminer`.

### Verificação Manual
1.  **Build da Stack**: Rodar `docker-compose -f portainer-stack.yml up -d`.
2.  **Checagem de Config**: Verificar limites do PHP via `phpinfo()` ou `php -i`.
3.  **Benchmark de Importação**:
    -   Observar redução significativa no tempo de importação comparado à versão anterior.
