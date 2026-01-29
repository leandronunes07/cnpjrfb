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
    ,'db_host' => 'my_mysql8' // Endereço do Servidor MySQL externo
    ,'db_port' => '3306'
    ,'db_name' => 'cnpjrfb_2026'
    ,'db_user' => 'root' // ATENÇÃO: Verifique a senha
    ,'db_password' => '123456' // ATENÇÃO: Verifique a senha
    ,'EXTRACTED_FILES_PATH'=>'/var/www/html/cargabd/extracted'
];