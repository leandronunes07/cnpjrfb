<?php
// === CNPJ Control Center v2.1 (Tailwind Edition) ===

// 1. Database Connection
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'cnpj_rfb';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: 'root';

try {
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Unknown database") !== false) {
        // Fallback for initialization
        $pdo = new PDO("mysql:host=$dbHost;port=$dbPort", $dbUser, $dbPass);
        $dbError = true;
    } else {
        die("<h1>Fatal Error: Database Connection Failed</h1><pre>".$e->getMessage()."</pre>");
    }
}

// 2. Action Handling
if (isset($_GET['action'])) {
    
    // reset_stuck
    if ($_GET['action'] == 'reset_stuck') {
         $pdo->exec("UPDATE controle_arquivos SET status='EXTRACTED' WHERE status='IMPORTING'");
         $pdo->exec("UPDATE controle_arquivos SET status='DOWNLOADED' WHERE status='EXTRACTING'");
         header("Location: status.php?msg=FilaResetada");
         exit;
    }

    // hard_reset
    if ($_GET['action'] == 'hard_reset') {
         $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
         $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
         foreach ($tables as $table) {
             $pdo->exec("DROP TABLE IF EXISTS `$table`");
         }
         $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
         
         $dirs = [getenv('EXTRACTED_FILES_PATH') ?: '/var/www/html/cargabd/extracted', __DIR__ . '/downloads'];
         foreach ($dirs as $dir) {
             if (is_dir($dir)) {
                 $files = glob("$dir/*");
                 foreach ($files as $file) { if (is_file($file)) unlink($file); }
             }
         }
         $logDir = __DIR__ . '/logs';
         if (is_dir($logDir)) {
             $logs = glob("$logDir/*");
             foreach ($logs as $log) { if (is_file($log)) unlink($log); }
         }
         header("Location: status.php?msg=HardResetCompleto");
         exit;
    }
    
    // install_cron
    if ($_GET['action'] == 'install_cron') {
         $logDir = __DIR__ . '/logs';
         if (!file_exists($logDir)) mkdir($logDir, 0777, true);
         
         // Build Env String
         $envs = [
            'DB_HOST' => getenv('DB_HOST'),
            'DB_PORT' => getenv('DB_PORT'),
            'DB_NAME' => getenv('DB_NAME'),
            'DB_USER' => getenv('DB_USER'),
            'DB_PASSWORD' => getenv('DB_PASSWORD'),
            'EXTRACTED_FILES_PATH' => getenv('EXTRACTED_FILES_PATH')
         ];
         $envStr = "";
         foreach($envs as $k=>$v) if($v) $envStr .= "$k='$v' ";
         
         $cmdPrefix = $envStr . "php";

         $cronContent = "* * * * * $cmdPrefix " . __DIR__ . "/automacao.php --stage=download >> $logDir/cron_download.log 2>&1\n" .
                        "* * * * * $cmdPrefix " . __DIR__ . "/automacao.php --stage=extract >> $logDir/cron_extract.log 2>&1\n" .
                        "* * * * * $cmdPrefix " . __DIR__ . "/automacao.php --stage=import >> $logDir/cron_import.log 2>&1\n" .
                        "0 */4 * * * $cmdPrefix " . __DIR__ . "/automacao.php >> $logDir/cron_discovery.log 2>&1\n";
         $tmp = tempnam(sys_get_temp_dir(), 'cron');
         file_put_contents($tmp, $cronContent);
         shell_exec("crontab $tmp");
         unlink($tmp);
         header("Location: status.php?msg=CronInstalado");
         exit;
    }
    
    // manual_start
    if ($_GET['manual_start'] == 1) {
        // Fast sync exec for discovery
        shell_exec("php " . __DIR__ . "/automacao.php > /dev/null 2>&1 &");
        header("Location: status.php?msg=DisparoManual");
        exit;
    }

    }

    // init_db
    if (isset($_GET['action']) && $_GET['action'] == 'init_db') {
         // Connect without DB selected
         $pdo = new PDO("mysql:host=$dbHost;port=$dbPort", $dbUser, $dbPass);
         $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
         
         // Create Schema
         $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
         $pdo->exec("USE `$dbName`");
         
         // Load SQL Files
         $files = [
            __DIR__ . '/../modelo_banco/mysql/modelo_fixed.sql', // Adjusted path relative to www/cargabd
            __DIR__ . '/../modelo_banco/mysql/monitoramento.sql'
         ];
         
         foreach ($files as $file) {
             if (file_exists($file)) {
                 $sql = file_get_contents($file);
                 // Fix hardcoded DB names
                 $sql = str_replace('`cnpjrfb_2026`', "`$dbName`", $sql);
                 $sql = str_replace('`dados_rfb`', "`$dbName`", $sql);
                 $pdo->exec($sql);
             }
         }
         
         header("Location: status.php?msg=DBInicializado");
         exit;
    }

// 3. Data Gathering
$qStats = [];
$total = 0;
$done = 0;
$progress = 0;
$activeJobs = [];
$dbError = false;

try {
    $qStats = $pdo->query("SELECT status, COUNT(*) as qtd FROM controle_arquivos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $total = array_sum($qStats);
    $done = $qStats['COMPLETED'] ?? 0;
    $progress = $total > 0 ? ($done / $total) * 100 : 0;
    
    $activeJobs = $pdo->query("SELECT * FROM controle_arquivos WHERE status IN ('DOWNLOADING','EXTRACTING','IMPORTING') ORDER BY updated_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist yet
    $dbError = true;
}

$dbRows = 0;
try {
    // Estimate Rows
    $stmt = $pdo->query("SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbName' OR TABLE_SCHEMA = '{$dbName}_temp'");
    $dbRows = $stmt->fetchColumn() ?: 0;
} catch(Exception $e) {}

// Cron Status Helper
function getCronStatus($name) {
    $path = __DIR__ . "/logs/cron_$name.log";
    if (!file_exists($path)) return ['s'=>'MISSING', 'c'=>'text-red-500', 'i'=>'❌'];
    $diff = time() - filemtime($path);
    if ($diff > 120) return ['s'=>'STALLED', 'c'=>'text-amber-500', 'i'=>'⚠️'];
    return ['s'=>'ONLINE', 'c'=>'text-emerald-500', 'i'=>'⚡'];
}

// Disk Usage (Approx)
$diskUsed = shell_exec("du -sh " . __DIR__ . "/downloads 2>/dev/null | cut -f1");
$extractedUsed = shell_exec("du -sh " . (getenv('EXTRACTED_FILES_PATH')?:'/var/www/html/cargabd/extracted') . " 2>/dev/null | cut -f1");
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNPJ Command Center</title>
    <meta http-equiv="refresh" content="10">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                fontFamily: { sans: ['Inter', 'sans-serif'], mono: ['JetBrains Mono', 'monospace'] },
                extend: { colors: { zinc: { 750: '#27272a', 850: '#18181b', 950: '#09090b' } } }
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .card-tech { @apply bg-zinc-900 border border-zinc-800 rounded-sm p-5; }
            .badge-tech { @apply px-2 py-0.5 text-xs font-bold uppercase tracking-wider rounded-sm; }
        }
    </style>
</head>
<body class="bg-zinc-950 text-zinc-300 min-h-screen p-4 md:p-8 antialiased selection:bg-emerald-500/30">

    <!-- HEADER / COMMAND DECK -->
    <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 border-b border-zinc-800 pb-6">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight flex items-center gap-3">
                <span class="w-3 h-3 bg-emerald-500 rounded-full animate-pulse"></span>
                CNPJ Control Center <span class="text-zinc-600 text-sm font-normal font-mono">v2.1</span>
            </h1>
            <div class="text-xs text-zinc-500 mt-1 font-mono flex gap-4">
                <span>DB: <span class="text-zinc-300"><?= getenv('DB_NAME') ?></span></span>
                <span>DISK: <span class="text-zinc-300">DL:<?= trim($diskUsed)?:'0B' ?> / EX:<?= trim($extractedUsed)?:'0B' ?></span></span>
            </div>
        </div>
        
        <div class="mt-4 md:mt-0 flex gap-3">
            <?php if($dbError): ?>
             <a href="?action=init_db" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-sm shadow-lg shadow-emerald-500/10 transition-all flex items-center gap-2 animate-bounce">
                🚀 Inicializar Banco
            </a>
            <?php endif; ?>
            <a href="?action=manual_start&manual_start=1" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold rounded-sm shadow-lg shadow-indigo-500/10 transition-all flex items-center gap-2">
                🔄 Force Check
            </a>
            <a href="?action=reset_stuck" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white text-sm font-bold rounded-sm shadow-lg shadow-amber-500/10 transition-all flex items-center gap-2">
                🔓 Reset Stuck
            </a>
             <button onclick="if(confirm('☢️ PERIGO: Isso apagará TODO o banco e arquivos. Continuar?')) window.location='?action=hard_reset'" class="px-4 py-2 border border-rose-900 text-rose-500 hover:bg-rose-950/30 text-sm font-bold rounded-sm transition-all flex items-center gap-2">
                ⛔ Hard Reset
            </button>
        </div>
    </header>

    <?php if(isset($dbError)): ?>
        <div class="mb-8 p-4 bg-amber-950/30 border border-amber-900/50 text-amber-200 rounded-sm flex items-center gap-3">
            <span class="text-2xl">⚠️</span>
            <div>
                <h3 class="font-bold">Banco de Dados não Inicializado!</h3>
                <p class="text-sm opacity-80">As tabelas necessárias não foram encontradas. Clique no botão <b>Inicializar Banco</b> acima.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI GRID -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <!-- GLOBAL PROGRESS -->
        <div class="card-tech md:col-span-2 relative overflow-hidden group">
            <div class="absolute top-0 right-0 p-2 opacity-10 group-hover:opacity-20 transition-opacity">
                <svg class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M4 4h16v16H4V4zm2 2v12h12V6H6z"/></svg>
            </div>
            <h3 class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-1">Progresso Global</h3>
            <div class="flex items-baseline gap-2 mb-2">
                <span class="text-4xl font-bold text-white"><?= number_format($progress, 1) ?>%</span>
                <span class="text-zinc-500 text-sm"><?= $done ?>/<?= $total ?> Arqs</span>
            </div>
            <div class="w-full bg-zinc-800 h-2 rounded-sm overflow-hidden">
                <div class="bg-emerald-500 h-full transition-all duration-500" style="width: <?= $progress ?>%"></div>
            </div>
        </div>

        <!-- ROW COUNT -->
        <div class="card-tech">
            <h3 class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-1">Registros (Est.)</h3>
            <div class="text-3xl font-bold text-sky-400 font-mono"><?= number_format($dbRows, 0, ',', '.') ?></div>
            <div class="text-xs text-zinc-600 mt-1">Linhas no Banco</div>
        </div>

        <!-- ERROR QUEUE -->
        <div class="card-tech">
            <h3 class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-1">Fila de Erros</h3>
            <div class="text-3xl font-bold text-rose-500 font-mono"><?= $qStats['ERROR'] ?? 0 ?></div>
            <div class="text-xs text-zinc-600 mt-1">Falhas (Retrying...)</div>
        </div>
    </div>

    <!-- MAIN DASHBOARD -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- LEFT COL: PIPELINE -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- PIPELINE FLOW -->
            <div class="card-tech">
                <div class="flex justify-between items-center mb-4 border-b border-zinc-800 pb-2">
                    <h3 class="font-bold text-white flex items-center gap-2">🏭 Data Pipeline</h3>
                    <span class="text-xs text-zinc-500 font-mono">Flow Visualization</span>
                </div>
                
                <div class="grid grid-cols-3 gap-2 text-center relative">
                    <!-- Lines connecting -->
                    <div class="absolute top-1/2 left-0 w-full h-0.5 bg-zinc-800 -z-10 -translate-y-1/2"></div>

                    <!-- Step 1: Download -->
                    <div class="bg-zinc-950 border border-zinc-800 p-4 rounded-sm relative group hover:border-sky-700 transition-colors">
                        <div class="absolute -top-3 left-1/2 -translate-x-1/2 bg-zinc-900 px-2 text-xs text-zinc-500 font-mono">ENTRY</div>
                        <div class="text-sky-500 font-bold mb-1">DOWNLOAD</div>
                        <div class="text-2xl font-mono text-white"><?= ($qStats['NEW']??0) + ($qStats['RETRY_DOWNLOAD']??0) ?></div>
                        <div class="text-xs text-zinc-600">Pending</div>
                    </div>

                    <!-- Step 2: Extract -->
                    <div class="bg-zinc-950 border border-zinc-800 p-4 rounded-sm relative group hover:border-amber-700 transition-colors">
                        <div class="text-amber-500 font-bold mb-1">EXTRACT</div>
                        <div class="text-2xl font-mono text-white"><?= $qStats['DOWNLOADED'] ?? 0 ?></div>
                        <div class="text-xs text-zinc-600">Zips Ready</div>
                    </div>

                    <!-- Step 3: Import -->
                    <div class="bg-zinc-950 border border-zinc-800 p-4 rounded-sm relative group hover:border-emerald-700 transition-colors">
                        <div class="text-emerald-500 font-bold mb-1">IMPORT</div>
                        <div class="text-2xl font-mono text-white"><?= $qStats['EXTRACTED'] ?? 0 ?></div>
                        <div class="text-xs text-zinc-600">CSVs Ready</div>
                    </div>
                </div>
            </div>

            <!-- ACTIVE JOBS TABLE -->
            <div class="card-tech min-h-[300px]">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-white">👷 Active Workers</h3>
                    <?php if(!empty($activeJobs)): ?>
                        <span class="flex h-2 w-2 relative">
                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                          <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if(empty($activeJobs)): ?>
                    <div class="flex flex-col items-center justify-center h-48 text-zinc-600">
                        <svg class="w-12 h-12 mb-2 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <p>Nenhum job ativo.</p>
                        <p class="text-xs">O Cron executa a cada 1 minuto.</p>
                        <a href="?action=install_cron" class="mt-4 text-xs text-emerald-500 hover:underline">Verificar Cron</a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-zinc-950 text-zinc-500 font-mono text-xs uppercase">
                                <tr>
                                    <th class="p-2">ID</th>
                                    <th class="p-2">File</th>
                                    <th class="p-2">Stage</th>
                                    <th class="p-2">Last Update</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-800 font-mono text-xs">
                                <?php foreach($activeJobs as $job): ?>
                                <tr class="hover:bg-zinc-800/50">
                                    <td class="p-2 text-zinc-400">#<?= $job['id'] ?></td>
                                    <td class="p-2 text-white font-bold"><?= $job['nome_arquivo'] ?></td>
                                    <td class="p-2">
                                        <span class="badge-tech <?php 
                                            echo match($job['status']) {
                                                'DOWNLOADING' => 'text-sky-400 bg-sky-950/30 border border-sky-900',
                                                'EXTRACTING' => 'text-amber-400 bg-amber-950/30 border border-amber-900',
                                                'IMPORTING' => 'text-emerald-400 bg-emerald-950/30 border border-emerald-900',
                                                default => 'text-zinc-500'
                                            };
                                        ?>"><?= $job['status'] ?></span>
                                    </td>
                                    <td class="p-2 text-zinc-500"><?= $job['updated_at'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <!-- RIGHT COL: SYSTEM HEALTH -->
        <div class="space-y-6">
            <!-- CRON STATUS -->
            <div class="card-tech">
                <h3 class="font-bold text-white mb-4 flex items-center gap-2">
                    💓 System Pulse
                    <a href="?action=install_cron" class="ml-auto text-xs text-emerald-500 hover:text-emerald-400 border border-emerald-900 px-2 py-1 rounded-sm">Repair Cron</a>
                </h3>
                <div class="space-y-3">
                    <?php 
                        $crons = ['Download','Extract','Import','Discovery'];
                        foreach($crons as $c): 
                            $st = getCronStatus(strtolower($c));
                    ?>
                    <div class="flex items-center justify-between text-sm bg-zinc-950 p-2 rounded-sm border border-zinc-900">
                        <span class="text-zinc-400"><?= $c ?> Agent</span>
                        <div class="flex items-center gap-2 font-mono text-xs">
                            <span class="<?= $st['c'] ?>"><?= $st['i'] ?> <?= $st['s'] ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- LATEST LOG TERMINAL -->
            <div class="card-tech bg-black !p-0 overflow-hidden border-zinc-800">
                <div class="bg-zinc-900 px-3 py-1 flex items-center justify-between">
                    <span class="text-xs text-zinc-400 font-mono">/logs/cron_import.log</span>
                    <div class="flex gap-1"><div class="w-2 h-2 rounded-full bg-red-500"></div><div class="w-2 h-2 rounded-full bg-yellow-500"></div><div class="w-2 h-2 rounded-full bg-green-500"></div></div>
                </div>
                <pre class="p-3 text-xs font-mono text-zinc-400 h-64 overflow-auto scrollbar-thin"><?php
                    $logFile = __DIR__ . '/logs/cron_import.log';
                    if(file_exists($logFile)) {
                        // Tail logic for PHP
                        $lines = file($logFile);
                        $lines = array_slice($lines, -15);
                        echo implode("", $lines);
                    } else {
                        echo "No logs yet.";
                    }
                ?></pre>
            </div>
        </div>
    </div>
    
    <!-- FILE LIST (Collapsible logic could be added, but listing all for now) -->
    <div class="card-tech mt-8">
        <h3 class="font-bold text-white mb-4">📂 Detailed File Status</h3>
        <div class="overflow-x-auto max-h-96 overflow-y-auto">
             <?php
                $allFiles = $pdo->query("SELECT * FROM controle_arquivos ORDER BY status='ERROR' DESC, updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
             ?>
             <table class="w-full text-left text-sm font-mono">
                <thead class="sticky top-0 bg-zinc-900 text-zinc-500 text-xs uppercase">
                    <tr>
                        <th class="p-2 border-b border-zinc-800">File</th>
                        <th class="p-2 border-b border-zinc-800">Type</th>
                        <th class="p-2 border-b border-zinc-800">Status</th>
                        <th class="p-2 border-b border-zinc-800">Error Msg</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    <?php foreach($allFiles as $f): ?>
                    <tr class="hover:bg-zinc-800/30 transition-colors">
                        <td class="p-2 text-zinc-300"><?= $f['nome_arquivo'] ?></td>
                        <td class="p-2 text-zinc-500"><?= $f['tipo'] ?></td>
                        <td class="p-2">
                             <span class="badge-tech <?php 
                                echo match($f['status']) {
                                    'COMPLETED' => 'text-emerald-400 bg-emerald-950/20',
                                    'ERROR' => 'text-rose-400 bg-rose-950/20',
                                    'NEW' => 'text-zinc-500 bg-zinc-800',
                                    default => 'text-amber-400 bg-amber-950/20'
                                };
                            ?>"><?= $f['status'] ?></span>
                        </td>
                        <td class="p-2 text-rose-400 text-xs truncate max-w-xs" title="<?= $f['mensagem_erro'] ?>"><?= $f['mensagem_erro'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
             </table>
        </div>
    </div>

</body>
</html>
