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
        if ($_GET['action'] == 'swap' && file_exists(__DIR__ . '/APPROVE_SWAP')) {
             // Force SWAP immediate
             shell_exec("/usr/local/bin/php " . __DIR__ . "/automacao.php --stage=swap > /dev/null 2>&1 &");
             header("Location: status.php?msg=SwapIniciado");
             exit;
        }
        if ($_GET['manual_start'] == 1) {
            // Force Discovery
             shell_exec("/usr/local/bin/php " . __DIR__ . "/automacao.php > /dev/null 2>&1 &");
             header("Location: status.php?msg=VerificacaoIniciada");
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
