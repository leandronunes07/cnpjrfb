<?php
/**
 * Download Helper - Runs on Windows HOST where network works
 * Downloads files and saves them to the shared volume
 */

$jsonFile = __DIR__ . '/discovered_files.json';

if (!file_exists($jsonFile)) {
    die("❌ Run discover-helper.php first!\n");
}

$data = json_decode(file_get_contents($jsonFile), true);
$baseUrl = $data['base_url'] ?? '';
$files = $data['files'] ?? [];

if (empty($files)) {
    die("❌ No files to download\n");
}

// Download directory (shared with container)
$downloadDir = __DIR__ . '/../../cargabd/download';
if (!is_dir($downloadDir)) {
    mkdir($downloadDir, 0777, true);
}

echo "📥 Downloading " . count($files) . " files from RFB...\n";
echo "📂 Saving to: $downloadDir\n\n";

$context = stream_context_create([
    "ssl" => [
        "verify_peer" => false, 
        "verify_peer_name" => false
    ],
    "http" => [
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
    ]
]);

$downloaded = 0;
$skipped = 0;
$errors = 0;

foreach ($files as $file) {
    $destPath = $downloadDir . '/' . $file;
    
    // Skip if already exists
    if (file_exists($destPath)) {
        echo "⏭️  Exists: $file\n";
        $skipped++;
        continue;
    }
    
    $url = $baseUrl . $file;
    echo "📥 Downloading: $file ... ";
    
    $content = @file_get_contents($url, false, $context);
    
    if ($content === false) {
        echo "❌ FAILED\n";
        $errors++;
        continue;
    }
    
    file_put_contents($destPath, $content);
    $size = strlen($content);
    $sizeMB = round($size / 1024 / 1024, 2);
    echo "✅ OK ({$sizeMB} MB)\n";
    $downloaded++;
    
    // Small delay to avoid overwhelming the server
    usleep(500000); // 0.5 seconds
}

echo "\n📊 Summary:\n";
echo "  ✅ Downloaded: $downloaded\n";
echo "  ⏭️  Skipped: $skipped\n";
echo "  ❌ Errors: $errors\n";
