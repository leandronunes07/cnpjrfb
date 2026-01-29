#!/bin/bash
# entrypoint.sh

# Salvar variáveis de ambiente para o Cron (importante para que o script PHP enxergue as variáveis)
printenv | grep -v "no_proxy" >> /etc/environment

# Iniciar o Cron em background
service cron start

# Iniciar o Apache em foreground
apachectl -D FOREGROUND
