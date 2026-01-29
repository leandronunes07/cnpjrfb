<?php
/**
 * db_drive = Drive do PDO  : mysql, sqlite, pgsql
 * db_host = endereço do servido de banco de dados
 * db_port = porta do servidor , null ou branco será considerado a porta default
 * 
 * EXTRACTED_FILES_PATH = caminho do arquivo, informe sempre entre aspas simples ''
 */
return [
     'db_drive' => 'mysql'
    ,'db_host' => getenv('DB_HOST') ?: 'localhost'
    ,'db_port' => getenv('DB_PORT') ?: '3306'
    ,'db_name' => getenv('DB_NAME') ?: 'cnpjrfb_2026'
    ,'db_user' => getenv('DB_USER') ?: 'root'
    ,'db_password' => getenv('DB_PASSWORD') ?: '123456'
    ,'EXTRACTED_FILES_PATH'=> getenv('EXTRACTED_FILES_PATH') ?: '/var/www/html/cargabd/extracted'
];