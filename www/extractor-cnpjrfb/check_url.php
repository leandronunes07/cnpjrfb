<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

$client = new Client([
    'verify' => false,
    'timeout' => 10,
    'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36']
]);

$paths = [
    'http://200.152.38.155/CNPJ/',
    'https://arquivos.receitafederal.gov.br/CNPJ/',
    'https://arquivos.receitafederal.gov.br/cnpj/',
    'https://arquivos.receitafederal.gov.br/dados/cnpj/',
    'https://arquivos.receitafederal.gov.br/dados/cnpj/dados_abertos_cnpj/',
    'http://dadosabertos.rfb.gov.br/CNPJ/'
];

echo "Testing URLs...\n";

foreach ($paths as $url) {
    echo "Checking: $url ... ";
    try {
        $response = $client->head($url); // HEAD first
        echo $response->getStatusCode();
    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            echo $e->getResponse()->getStatusCode();
        } else {
            echo "Error (" . $e->getMessage() . ")";
        }
    }
    echo "\n";
}
