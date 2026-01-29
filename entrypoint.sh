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
# 4. Configurar Cron (Explicito para root)
echo "Setting up root crontab..."
echo "0 2 * * * /usr/local/bin/php /var/www/html/cargabd/automacao.php >> /var/log/cron.log 2>&1" | crontab -

# 5. Criar arquivo de log do Cron
touch /var/log/cron.log
chmod 666 /var/log/cron.log

# 6. Iniciar serviços
service cron start
apachectl -D FOREGROUND
