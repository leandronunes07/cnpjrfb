#!/bin/bash
# entrypoint.sh

# 1. Salvar variáveis de ambiente para o Cron
printenv | grep -v "no_proxy" >> /etc/environment

# 2. Setup de Pastas e Permissões (Robustez)
echo "Configuring permissions..."

# Cria pastas se não existirem
mkdir -p /var/www/html/cargabd/logs
mkdir -p /var/www/html/cargabd/download
mkdir -p /var/www/html/cargabd/extracted
mkdir -p /tmp

# Ajusta Permissões (777 para garantir escrita sem dor de cabeça em ambiente docker/windows)
chmod -R 777 /var/www/html/cargabd/logs
chmod -R 777 /var/www/html/cargabd/download
chmod -R 777 /var/www/html/cargabd/extracted
chmod 777 /tmp

# 3. Sanitização de Scripts (Remove Windows CRLF \r)
if [ -d "/var/www/html/cargabd/download" ]; then
    echo "Sanitizing shell scripts in download folder..."
    find /var/www/html/cargabd/download -name "*.sh" -exec sed -i 's/\r$//' {} +
    find /var/www/html/cargabd/download -name "*.sh" -exec chmod +x {} +
fi

# 4. Iniciar o Cron em background
# 4. Configurar Cron (Pipeline Stage) - Roda a cada minuto para manter Workers ativos
echo "Setting up pipeline crons..."
PHP_BIN=$(which php)
echo "PHP Binary found at: $PHP_BIN"

# Limpa crons anteriores
crontab -r || true

# Cria arquivo temporário de cron
cat <<EOF > /tmp/cron_jobs
* * * * * $PHP_BIN /var/www/html/cargabd/automacao.php --stage=download >> /var/log/cron_download.log 2>&1
* * * * * $PHP_BIN /var/www/html/cargabd/automacao.php --stage=extract >> /var/log/cron_extract.log 2>&1
* * * * * $PHP_BIN /var/www/html/cargabd/automacao.php --stage=import >> /var/log/cron_import.log 2>&1
0 */4 * * * $PHP_BIN /var/www/html/cargabd/automacao.php >> /var/log/cron_discovery.log 2>&1
EOF

# Aplica
crontab /tmp/cron_jobs
rm /tmp/cron_jobs

# 5. Criar logs
touch /var/log/cron_download.log /var/log/cron_extract.log /var/log/cron_import.log /var/log/cron_discovery.log
chmod 666 /var/log/cron_*.log

# 6. Iniciar serviços
service cron start
apachectl -D FOREGROUND
