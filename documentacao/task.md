# Lista de Tarefas - Otimização e Deploy do Projeto CNPJ

- [x] Análise e Descoberta do Projeto <!-- id: 0 -->
    - [x] Analisar configuração Docker existente (`docker-compose.yml`, `Dockerfile`) <!-- id: 1 -->
    - [x] Analisar estrutura da aplicação (diretório `www`) <!-- id: 2 -->
    - [x] Identificar gargalos de performance (importação do banco, processo de download) <!-- id: 3 -->
    - [x] Documentar descobertas e oportunidades de melhoria <!-- id: 4 -->
- [x] Melhorias de Infraestrutura <!-- id: 5 -->
    - [x] Otimizar tamanho da imagem Docker e processo de build <!-- id: 6 -->
    - [x] Ajustar configurações de PHP e Banco de Dados (`php.ini`, configurações MariaDB) <!-- id: 7 -->
- [x] Preparação da Stack Portainer <!-- id: 8 -->
    - [x] Criar/Atualizar `docker-compose.yml` para compatibilidade com Portainer <!-- id: 9 -->
    - [x] Verificar variáveis de ambiente e mapeamento de volumes <!-- id: 10 -->
- [x] Refatoração e Otimização (Opcional - baseado nas descobertas) <!-- id: 11 -->
    - [x] Refatorar lógica de download/importação para velocidade <!-- id: 12 -->
    - [x] Implementar mecanismos de cache ou filas se aplicável <!-- id: 13 -->
