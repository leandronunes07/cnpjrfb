<?php
// Quick URL test
$url = 'https://arquivos.receitafederal.gov.br/dados/cnpj/dados_abertos_cnpj/';

echo "Testing URL: $url\n\n";

$context = stream_context_create([
    "ssl" => [
        "verify_peer" => false, 
        "verify_peer_name" => false
    ],
    "http" => [
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
]);

$html = @file_get_contents($url, false, $context);

if ($html) {
    echo "SUCCESS! Got " . strlen($html) . " bytes\n";
    echo "First 500 chars:\n";
    echo substr($html, 0, 500) . "\n";
} else {
    echo "FAILED to fetch\n";
    $error = error_get_last();
    if ($error) {
        echo "Error: " . $error['message'] . "\n";
    }
}
