<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNPJ Control Center v2</title>
    <meta http-equiv="refresh" content="10">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        :root { --bg: #111; --card: #1a1b1e; --accent: #3b82f6; --success: #22c55e; --warning: #f59e0b; --danger: #ef4444; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #e1e1e6; margin: 0; padding: 20px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card { background: var(--card); padding: 20px; border-radius: 12px; border: 1px solid #333; }
        h1, h2, h3 { margin: 0 0 15px 0; }
        .metric { font-size: 2rem; font-weight: 800; }
        .metric span { font-size: 1rem; color: #888; font-weight: 400; }
        .progress-bar { height: 8px; background: #333; border-radius: 4px; overflow: hidden; margin-top: 10px; }
        .progress-fill { height: 100%; transition: width 0.3s ease; }
        
        /* Tabs */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab { background: #222; padding: 10px 20px; border-radius: 8px; cursor: pointer; border: 1px solid transparent; }
        .tab.active { background: var(--accent); color: white; border-color: var(--accent); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 12px; border-bottom: 1px solid #333; }
        th { color: #888; font-size: 0.8rem; text-transform: uppercase; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .badge.NEW { background: #444; }
        .badge.DOWNLOADING { background: #60a5fa; color: #1e3a8a; }
        .badge.DOWNLOADED { background: #3b82f6; }
        .badge.EXTRACTING { background: #f59e0b; color: #451a03; }
        .badge.EXTRACTED { background: #d97706; }
        .badge.IMPORTING { background: #a855f7; color: #3b0764; }
        .badge.COMPLETED { background: var(--success); color: #052e16; }
        .badge.ERROR { background: var(--danger); color: white; }
    </style>
     <script>
        function showTab(id) {
            document.querySelectorAll('.tab-content').forEach(d => d.style.display = 'none');
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(id).style.display = 'block';
            document.querySelector(`[data-tab="${id}"]`).classList.add('active');
            localStorage.setItem('activeTab', id);
        }
        window.onload = () => showTab(localStorage.getItem('activeTab') || 'pipeline');
    </script>
</head>
<body>
    <?php
    // Conect DB
    $pdo = new PDO("mysql:host=".getenv('DB_HOST').";port=".getenv('DB_PORT').";dbname=".getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'));
    
    // Handle Actions
    if (isset($_GET['action'])) {
        // Build Env Exports
        $envVars = "export DB_HOST='".getenv('DB_HOST')."' ".
                   "DB_PORT='".getenv('DB_PORT')."' ".
                   "DB_NAME='".getenv('DB_NAME')."' ".
                   "DB_USER='".getenv('DB_USER')."' ".
                   "DB_PASSWORD='".getenv('DB_PASSWORD')."';";
                   
        $phpBin = 'php'; 

                   
        if ($_GET['action'] == 'swap' && file_exists(__DIR__ . '/APPROVE_SWAP')) {
             // Force SWAP immediate
             $cmd = "$envVars $phpBin " . __DIR__ . "/automacao.php --stage=swap > /tmp/cnpj_swap.log 2>&1 &";
             shell_exec($cmd);
             header("Location: status.php?msg=SwapIniciado");
             exit;
        }
        if ($_GET['manual_start'] == 1) {
            // Force Discovery (SYNC DEBUG MODE)
             $cmd = "$envVars $phpBin " . __DIR__ . "/automacao.php 2>&1";
             $output = shell_exec($cmd);
             
             echo "<pre><h1>🕵️‍♂️ DEBUG INFO</h1>";
             
             echo "<b>1. Teste Manual (automacao.php):</b>\n";
             var_dump($output);
             
             echo "\n<b>2. Verificando Crontab (Usuário atual):</b>\n";
             echo shell_exec("crontab -l 2>&1");
             
             echo "\n<b>3. Verificando Processos (Cron rodando?):</b>\n";
             echo shell_exec("ps aux | grep cron 2>&1");
             
             echo "\n<b>4. Listando Logs (/var/log):</b>\n";
             echo shell_exec("ls -la /var/log/cron* 2>&1");
             
             echo "</pre>";
             
             // Check if table exists now
             try {
                $p = new PDO("mysql:host=".getenv('DB_HOST').";port=".getenv('DB_PORT').";dbname=".getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASSWORD'));
                $p->query("SELECT 1 FROM controle_arquivos LIMIT 1");
                echo "<h3>✅ Tabela controle_arquivos detectada!</h3>";
                echo "<a href='status.php'>Voltar para Dashboard</a>";
             } catch(Exception $e) {
                 echo "<h3>❌ Tabela ainda não existe.</h3>";
             }
             exit;
        }
        
        if ($_GET['action'] == 'install_cron') {
             $logDir = __DIR__ . '/logs';
             if (!file_exists($logDir)) mkdir($logDir, 0777, true);
             
             $cronContent = "* * * * * php " . __DIR__ . "/automacao.php --stage=download >> $logDir/cron_download.log 2>&1\n" .
                            "* * * * * php " . __DIR__ . "/automacao.php --stage=extract >> $logDir/cron_extract.log 2>&1\n" .
                            "* * * * * php " . __DIR__ . "/automacao.php --stage=import >> $logDir/cron_import.log 2>&1\n" .
                            "0 */4 * * * php " . __DIR__ . "/automacao.php >> $logDir/cron_discovery.log 2>&1\n";
             
             // Install 
             $tmp = tempnam(sys_get_temp_dir(), 'cron');
             file_put_contents($tmp, $cronContent);
             
             $cmd = "crontab $tmp 2>&1";
             $out = shell_exec($cmd);
             unlink($tmp);
             
             if ($out) die("<h1>Erro ao instalar Cron:</h1><pre>$out</pre>");
             
             header("Location: status.php?msg=CronInstalado");
             exit;
        }
        
        if ($_GET['action'] == 'reset_stuck') {
             $pdo->exec("UPDATE controle_arquivos SET status='EXTRACTED' WHERE status='IMPORTING'");
             $pdo->exec("UPDATE controle_arquivos SET status='DOWNLOADED' WHERE status='EXTRACTING'");
             header("Location: status.php?msg=FilaResetada");
             exit;
        }
        
        if ($_GET['action'] == 'debug_job' && isset($_GET['id'])) {
             $id = (int)$_GET['id'];
             echo "<pre><h1>🐞 Debug Job #$id</h1>";
             
             // Manually load Automation class logic (simplified)
             $stmt = $pdo->prepare("SELECT * FROM controle_arquivos WHERE id = ?");
             $stmt->execute([$id]);
             $job = $stmt->fetch(PDO::FETCH_ASSOC);
             
             if (!$job) die("Job não encontrado");
             
             echo "Job Data: " . print_r($job, true) . "\n";
             
             // Check ENV
             echo "EXTRACTED_FILES_PATH: " . (getenv('EXTRACTED_FILES_PATH') ?: 'N/A') . "\n";
             
             // Check File
             $extractDir = getenv('EXTRACTED_FILES_PATH') ?: '/var/www/html/cargabd/extracted';
             $targetCsv = "$extractDir/job_{$id}.csv";
             
             echo "Target CSV: $targetCsv\n";
             if (file_exists($targetCsv)) {
                 echo "✅ Arquivo EXISTE! Tamanho: " . filesize($targetCsv) . " bytes\n";
                 echo "Permissões: " . substr(sprintf('%o', fileperms($targetCsv)), -4) . "\n";
             } else {
                 echo "❌ Arquivo NÃO ENCONTRADO.\n";
                 // List dir
                 echo "Conteúdo da pasta $extractDir:\n";
                 print_r(scandir($extractDir));
             }
             
             echo "\n\nPara executar a importação real, o script precisa rodar em CLI (Background).";
             echo "</pre>";
             exit;
        }
    }
    // Stats
    $qStats = [];
    $total = 0;
    $done = 0;
    $progress = 0;
    $jobs = [];
    $dbError = false;

    try {
        $qStats = $pdo->query("SELECT status, COUNT(*) as qtd FROM controle_arquivos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $total = array_sum($qStats);
        $done = $qStats['COMPLETED'] ?? 0;
        $progress = $total > 0 ? ($done / $total) * 100 : 0;
        
        // Active Jobs
        $jobs = $pdo->query("SELECT * FROM controle_arquivos WHERE status IN ('DOWNLOADING','EXTRACTING','IMPORTING') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $dbError = true;
    }
    
    // DB Stats (Estimate)
    $dbName = getenv('DB_NAME') . '_temp';
    $rows = 0;
    try {
        $stmt = $pdo->query("SELECT SUM(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbName'");
        $rows = $stmt->fetchColumn() ?: 0;
    } catch(Exception $e) {}
    ?>

    <div style="display:flex; justify-content:space-between; align-items:center;">
         <h1>🚀 CNPJ Pipeline Monitor</h1>
         <div>
             <span class="badge" style="font-size:1rem; background: #333;">DB: <?= getenv('DB_NAME') ?></span>
             <a href="status.php?action=manual_start&manual_start=1" class="badge" style="background:#8257e5; text-decoration:none; margin-left:10px;">🔄 Force Check</a>
         </div>
    </div>
    
    <?php if($dbError): ?>
        <div class="card" style="border-left: 4px solid var(--warning);">
            <h3>⏳ Inicializando Sistema...</h3>
            <p>A estrutura do banco de dados ainda não foi criada.</p>
            <p>O robô de automação criar as tabelas automaticamente na primeira execução.</p>
            <p><b>Aguarde 1 minuto</b> (Cron Job) ou clique em <b>Force Check</b> acima para iniciar agora.</p>
        </div>
        <?php exit; ?>
    <?php endif; ?>

    <!-- Cron Heartbeat -->
    <?php
        $logDir = __DIR__ . '/logs';
        if (!file_exists($logDir)) mkdir($logDir, 0777, true);
        
        $cronLogs = [
            'Download' => $logDir . '/cron_download.log',
            'Extract'  => $logDir . '/cron_extract.log',
            'Import'   => $logDir . '/cron_import.log'
        ];

        function getCronStatus($logFile) {
            if (!file_exists($logFile)) return ['status' => 'MISSING', 'time' => 'N/A', 'diff' => 9999];
            $time = filemtime($logFile);
            $diff = time() - $time;
            
            // Tolerance logic
            $status = 'ONLINE';
            if ($diff > 120) $status = 'STALLED';
            
            return ['status' => $status, 'time' => date('H:i:s', $time), 'diff' => $diff];
        }
    ?>
    <div class="card" style="margin-bottom: 20px; padding: 15px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid #333; padding-bottom:10px;">
             <h3 style="margin:0;">💓 Health Check (System Crons)</h3>
             <div>
                <a href="status.php?action=reset_stuck" class="badge" style="background:#f59e0b; text-decoration:none; margin-right:10px;">🔓 Resetar Travamentos</a>
                <a href="status.php?action=install_cron" class="badge" style="background:#059669; text-decoration:none;">🛠️ Reparar/Instalar Cron</a>
             </div>
        </div>
        
        <div style="display:flex; gap: 20px; flex-wrap:wrap;">
            <?php foreach($cronLogs as $name => $path): 
                $st = getCronStatus($path);
                $color = $st['status'] == 'ONLINE' ? '#22c55e' : ($st['status']=='MISSING' ? '#ef4444' : '#eab308');
                $icon = $st['status'] == 'ONLINE' ? '✅' : '⚠️';
            ?>
            <div style="border: 1px solid #444; padding: 10px; border-radius: 6px; flex: 1; min-width: 150px; background: #1f1f23;">
                <div style="font-weight:bold; color:#aaa; margin-bottom:5px;"><?= $icon ?> <?= $name ?> Worker</div>
                <div style="font-size: 1.2rem; color: <?= $color ?>"><?= $st['status'] ?></div>
                <div style="font-size: 0.8rem; color:#666;">Log: relative/logs/<?= basename($path) ?></div>
                <div style="font-size: 0.7rem; color:#555;"><?= $st['diff'] ?>s atrás</div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:10px; font-size:0.8em; color:#666;">
            * Se estiver MISSING, clique em "Reparar/Instalar Cron".
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="grid" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Progresso Global</h3>
            <div class="metric"><?= number_format($progress, 1) ?>%</div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $progress ?>%; background: var(--success);"></div>
            </div>
            <small style="color:#888"><?= $done ?> de <?= $total ?> arquivos</small>
        </div>
        
        <div class="card">
            <h3>Registros Importados (Temp)</h3>
            <div class="metric" style="color: var(--accent);"><?= number_format($rows, 0, ',', '.') ?></div>
            <small>Linhas aproximadas no MySQL</small>
        </div>
        
        <div class="card">
            <h3>Fila de Erros</h3>
            <div class="metric" style="color: var(--danger);"><?= $qStats['ERROR'] ?? 0 ?></div>
            <small>Tentativas automáticas em andamento</small>
        </div>
    </div>

    <!-- TABS -->
    <div class="tabs">
        <div class="tab" data-tab="pipeline" onclick="showTab('pipeline')">🏭 Pipeline</div>
        <div class="tab" data-tab="jobs" onclick="showTab('jobs')">👷 Jobs Ativos</div>
        <div class="tab" data-tab="files" onclick="showTab('files')">📁 Arquivos</div>
    </div>

    <!-- TAB: PIPELINE -->
    <div id="pipeline" class="tab-content active">
        <div class="grid">
            <div class="card" style="border-left: 4px solid var(--accent);">
                <h3>1. Download Queue</h3> 
                <div class="metric"><?= ($qStats['NEW'] ?? 0) + ($qStats['RETRY_DOWNLOAD'] ?? 0) ?></div>
                <small>Aguardando Download</small>
            </div>
            <div class="card" style="border-left: 4px solid var(--warning);">
                <h3>2. Extract Queue</h3>
                <div class="metric"><?= $qStats['DOWNLOADED'] ?? 0 ?></div>
                <small>Baixados (Prontos p/ Extrair)</small>
            </div>
            <div class="card" style="border-left: 4px solid #a855f7;">
                <h3>3. Import Queue</h3>
                <div class="metric"><?= $qStats['EXTRACTED'] ?? 0 ?></div>
                <small>Extraídos (Prontos p/ Importar)</small>
            </div>
        </div>
        
        <div class="card" style="margin-top: 20px;">
            <h3>ℹ️ Logs Recentes (Discovery)</h3>
             <pre style="background:#000; padding:10px; height: 150px; overflow:auto; color: #aaa; font-size: 0.8rem;"><?= htmlspecialchars(shell_exec('tail -n 10 /tmp/cnpj_automacao.log')) ?></pre>
        </div>
    </div>

    <!-- TAB: JOBS -->
    <div id="jobs" class="tab-content">
        <div class="card">
            <h3>Monitoramento de Workers Ativos</h3>
            <?php if(empty($jobs)): ?>
                <div style="padding:40px; text-align:center; color:#555;">💤 Nenhum worker trabalhando no momento. (Aguardando Cron ou Trigger)</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>ID</th><th>Arquivo</th><th>Fase</th><th>Status</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach($jobs as $job): ?>
                        <tr>
                            <td>#<?= $job['id'] ?></td>
                            <td><?= $job['nome_arquivo'] ?></td>
                            <td><?= $job['status'] ?></td>
                            <td><span class="badge <?= $job['status'] ?>"><?= $job['status'] ?></span></td>
                            <td><?= $job['updated_at'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB: FILES -->
    <div id="files" class="tab-content">
        <div class="card">
            <h3>Detalhamento por Arquivo</h3>
            <?php
            // Safe query for table rendering
            $allFiles = [];
            try {
                 $allFiles = $pdo->query("SELECT * FROM controle_arquivos ORDER BY status='ERROR' DESC, updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            } catch(Exception $e) {}
            ?>
            <table>
                <thead><tr><th>ID</th><th>Arquivo</th><th>Tipo</th><th>Status</th><th>Erro</th></tr></thead>
                <tbody>
                <?php foreach($allFiles as $f): ?>
                    <tr>
                        <td><?= $f['id'] ?></td>
                        <td><?= $f['nome_arquivo'] ?></td>
                        <td><?= $f['tipo'] ?></td>
                        <td><span class="badge <?= $f['status'] ?>"><?= $f['status'] ?></span></td>
                        <td style="color:var(--danger)"><?= substr($f['mensagem_erro'] ?? '', 0, 50) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
