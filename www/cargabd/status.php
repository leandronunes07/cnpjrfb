<?php
/**
 * CNPJ Control Center - Dashboard Unificado
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/controllers/TPDOConnection.class.php';

// Conexão DB
$pdo = new PDO("mysql:host=".getenv('DB_HOST').";port=".getenv('DB_PORT'), getenv('DB_USER'), getenv('DB_PASSWORD'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("USE `".getenv('DB_NAME')."`");

// 1. Force Start Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_start') {
    try {
        $folder = $_POST['folder'];
        // 1. Atualiza Status
        $pdo->prepare("UPDATE monitoramento_rfb SET status = 'FORCE_START', log = 'Início forçado pelo Dashboard' WHERE pasta_rfb = ?")->execute([$folder]);
        
        // 2. Dispara Processo em Background (Linux)
        // Isso garante que o Apache não trave esperando o script terminar
        $cmd = "nohup php /var/www/html/cargabd/automacao.php > /dev/null 2>&1 &";
        exec($cmd);
        
        $msgSuccess = "Comando disparado! O robô iniciou a execução em background.";
    } catch (Exception $e) {
        $msgClass = "error";
        $msgText = "Erro ao forçar início: " . $e->getMessage();
    }
}

// 2. Load Status
try {
    $latest = $pdo->query("SELECT * FROM monitoramento_rfb ORDER BY data_detectada DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $history = $pdo->query("SELECT * FROM monitoramento_rfb ORDER BY data_detectada DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ($e->getCode() == '42S02') { // Table missing
        $latest = null; $history = [];
    } else throw $e;
}

// 3. Live Logs
$logFile = '/tmp/cnpj_automacao.log';
$logs = file_exists($logFile) ? implode("", array_slice(file($logFile), -100)) : "Sem logs disponíveis.";

// Determine System State
$systemState = "Indefinido";
$stateColor = "gray";

if ($latest) {
    switch ($latest['status']) {
        case 'NEW': 
        case 'PENDING_APPROVAL':
            $systemState = "Aguardando Prazo (3 dias)";
            $stateColor = "orange";
            break;
        case 'FORCE_START':
            $systemState = "Iniciando Force Start...";
            $stateColor = "blue"; 
            break;
        case 'Processing (Temp)':
        case 'PROCESSING':
            $systemState = "Em Processamento (Carga)";
            $stateColor = "blue";
            break;
        case 'WAITING_VALIDATION':
            $systemState = "Aguardando Aprovação (Validação)";
            $stateColor = "yellow";
            break;
        case 'COMPLETED':
            $systemState = "Sistema Operacional (Atualizado)";
            $stateColor = "green";
            break;
        case 'ERROR':
            $systemState = "ERRO - Atenção Necessária";
            $stateColor = "red";
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNPJ Control Center</title>
    <meta http-equiv="refresh" content="60">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #1a1b1e; color: #e1e1e6; margin: 0; padding: 2rem; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* Header Stats */
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .title h1 { margin: 0; font-weight: 800; font-size: 1.8rem; background: linear-gradient(90deg, #8257e5, #50fa7b); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .title span { font-size: 0.9rem; color: #a8a8b3; }
        
        /* Cards Grid */
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .card { background: #202024; border-radius: 8px; padding: 1.5rem; border: 1px solid #323238; }
        .card h3 { margin-top: 0; color: #a8a8b3; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .big-stat { font-size: 1.5rem; font-weight: bold; margin: 10px 0; display: flex; align-items: center; gap: 10px; }
        
        .state-dot { height: 12px; width: 12px; border-radius: 50%; display: inline-block; }
        .dot-green { background: #50fa7b; box-shadow: 0 0 10px rgba(80, 250, 123, 0.4); }
        .dot-blue { background: #8be9fd; animation: pulse 2s infinite; }
        .dot-orange { background: #ffb86c; }
        .dot-yellow { background: #f1fa8c; animation: pulse 1s infinite; }
        .dot-red { background: #ff5555; }
        
        /* Action Button */
        .btn-action { display: inline-block; background: #50fa7b; color: #1a1b1e; padding: 0.8rem 1.5rem; border-radius: 6px; text-decoration: none; font-weight: bold; transition: transform 0.2s; }
        .btn-action:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .btn-force { background: #ffb86c; color: #444; }
        
        /* Tables */
        .table-container { background: #202024; border-radius: 8px; overflow: hidden; border: 1px solid #323238; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #29292e; }
        th { background: #29292e; color: #a8a8b3; font-size: 0.8rem; text-transform: uppercase; }
        tr:last-child td { border-bottom: none; }
        
        /* Log Terminal */
        .terminal { background: #0d0d0d; color: #a8a8b3; font-family: 'Consolas', monospace; padding: 1rem; border-radius: 8px; height: 300px; overflow-y: scroll; font-size: 0.85rem; line-height: 1.5; border: 1px solid #323238; }
        
        .alert-success { background: #50fa7b; color: #1a1b1e; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; font-weight: bold; }
        .alert-error { background: #ff5555; color: white; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; font-weight: bold; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">
                <h1>CNPJ Control Center</h1>
                <span>Orquestração Automática & Deploy Blue-Green</span>
            </div>
            <div>
                 <span style="font-size: 0.8rem; color: #777;">Atualizado em: <?= date('H:i:s') ?></span>
            </div>
        </div>

        <?php if(isset($msgSuccess)): ?> <div class="alert-success">✅ <?= $msgSuccess ?></div> <?php endif; ?>
        <?php if(isset($msgText) && isset($msgClass) && $msgClass == 'error'): ?> <div class="alert-error">❌ <?= $msgText ?></div> <?php endif; ?>

        <!-- Main Status Cards -->
        <div class="grid">
            <!-- Status Card -->
            <div class="card">
                <h3>Estado do Sistema</h3>
                <div class="big-stat" style="color: <?= $stateColor == 'yellow' ? '#f1fa8c' : ($stateColor == 'green' ? '#50fa7b' : 'white') ?>">
                    <span class="state-dot dot-<?= $stateColor ?>"></span>
                    <?= $systemState ?>
                </div>
                <?php if ($latest && isset($latest['approval_token']) && $latest['status'] == 'WAITING_VALIDATION'): ?>
                     <div style="margin-top: 1rem;">
                        <a href="approval_dashboard.php?token=<?= $latest['approval_token'] ?>" class="btn-action">
                            🚀 APROVAR DEPLOY AGORA
                        </a>
                     </div>
                <?php endif; ?>
                
                <?php if ($latest && ($latest['status'] == 'PENDING_APPROVAL' || $latest['status'] == 'NEW')): ?>
                     <div style="margin-top: 1rem;">
                        <form method="POST" onsubmit="return confirm('Tem certeza? Isso vai pular os 3 dias de espera e começar a carga pesada agora.');">
                            <input type="hidden" name="folder" value="<?= $latest['pasta_rfb'] ?>">
                            <button type="submit" name="action" value="force_start" class="btn-action btn-force">
                                ⚡ FORCE START (Ignorar Prazo)
                            </button>
                        </form>
                     </div>
                <?php endif; ?>

                <?php if ($latest && $latest['status'] == 'WAITING_VALIDATION'): ?>
                    <p style="color: #a8a8b3; font-size: 0.9rem; margin-top: 10px;">
                        O link também foi enviado para o seu e-mail.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Last Version Card -->
            <div class="card">
                <h3>Versão Atual (Ref. RFB)</h3>
                <div class="big-stat">
                    <?= $latest ? htmlspecialchars($latest['pasta_rfb']) : 'N/A' ?>
                </div>
                <p style="color: #a8a8b3; font-size: 0.9rem;">
                    Detectado em: <?= $latest ? date('d/m/Y H:i', strtotime($latest['data_detectada'])) : '-' ?>
                </p>
            </div>
            
            <!-- Quick Stats -->
            <div class="card">
                <h3>Monitoramento</h3>
                <div style="margin-top: 10px; font-size: 0.9rem; color: #e1e1e6;">
                    <div>• Banco de Dados: <b><?= getenv('DB_NAME') ?></b></div>
                    <div>• Host: <b><?= getenv('DB_HOST') ?></b></div>
                    <div>• Modo: <b>Blue-Green</b> (Seguro)</div>
                </div>
            </div>
        </div>

        <!-- History Table -->
        <h3>📜 Histórico de Versões</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Pasta RFB</th>
                        <th>Status</th>
                        <th>Detectado</th>
                        <th>Última Mensagem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr><td colspan="4" style="text-align:center; padding: 2rem; color: #777;">Nenhum registro encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($history as $row): ?>
                        <tr>
                            <td><b><?= htmlspecialchars($row['pasta_rfb']) ?></b></td>
                            <td>
                                <span style="
                                    background: <?= strpos($row['status'], 'ERROR') !== false ? '#ff5555' : '#29292e' ?>;
                                    padding: 4px 8px; border-radius: 4px; font-size: 0.8rem;
                                    color: <?= strpos($row['status'], 'COMPLETED') !== false ? '#50fa7b' : 'white' ?>
                                ">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d/m/y H:i', strtotime($row['data_detectada'])) ?></td>
                            <td style="color: #a8a8b3;"><?= htmlspecialchars(substr($row['log'] ?? '', 0, 80)) ?>...</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Terminal Logs -->
        <h3 style="margin-top: 2rem;">💻 Terminal Log (Live Tail)</h3>
        <div class="terminal">
            <pre><?= htmlspecialchars($logs) ?></pre>
        </div>
    </div>
</body>
</html>
