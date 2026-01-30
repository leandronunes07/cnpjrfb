<?php
/**
 * Discovery Helper - Runs on HOST (Windows) where network works
 * Saves results to file that container can read
 */

$url = 'https://arquivos.receitafederal.gov.br/dados/cnpj/dados_abertos_cnpj/';

echo "Fetching from RFB...\n";

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

if (!$html) {
    die("Failed to fetch RFB page\n");
}

// Strategy 1: Look for direct ZIP files
preg_match_all('/href="([^"]+\.zip)"/i', $html, $matches);

if (!empty($matches[1])) {
    $files = array_unique($matches[1]);
    echo "Found " . count($files) . " ZIP files directly\n";
    file_put_contents(__DIR__ . '/discovered_files.json', json_encode($files, JSON_PRETTY_PRINT));
    exit(0);
}

// Strategy 2: Look for date folders
preg_match_all('/href="([0-9]{4}-[0-9]{2})\/?"/i', $html, $dirMatches);

if (!empty($dirMatches[1])) {
    $dirs = array_unique($dirMatches[1]);
    rsort($dirs);
    $latestDir = $dirs[0];
    
    echo "Found subdirectories. Using latest: $latestDir\n";
    
    $subUrl = $url . $latestDir . '/';
    $htmlSub = @file_get_contents($subUrl, false, $context);
    
    if ($htmlSub) {
        preg_match_all('/href="([^"]+\.zip)"/i', $htmlSub, $subMatches);
        
        if (!empty($subMatches[1])) {
            $files = array_unique($subMatches[1]);
            echo "Found " . count($files) . " ZIP files in $latestDir\n";
            
            $result = [
                'base_url' => $subUrl,
                'files' => $files
            ];
            
            file_put_contents(__DIR__ . '/discovered_files.json', json_encode($result, JSON_PRETTY_PRINT));
            exit(0);
        }
    }
}

echo "No files found\n";
exit(1);
