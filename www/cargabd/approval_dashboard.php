<?php
/**
 * Dashboard de Aprovação Blue-Green
 * Acessível via Link Seguro enviado por E-mail
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/controllers/TPDOConnection.class.php';

$token = $_GET['token'] ?? '';
$action = $_POST['action'] ?? '';

if (!$token) die("Acesso Negado.");

// Conect DB
$pdo = new PDO("mysql:host=".getenv('DB_HOST').";port=".getenv('DB_PORT'), getenv('DB_USER'), getenv('DB_PASSWORD'));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("USE `".getenv('DB_NAME')."`"); // Usa banco principal para checar tabela de controle

// Validar Token
$stmt = $pdo->prepare("SELECT * FROM monitoramento_rfb WHERE approval_token = ? AND status = 'WAITING_VALIDATION'");
$stmt->execute([$token]);
$deploy = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deploy) die("Link inválido ou já processado.");

$msg = "";

// Processar Ação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    if ($action === 'APPROVE') {
        // Criar arquivo de gatilho para o CRON (Swap Seguro)
        file_put_contents(__DIR__ . '/APPROVE_SWAP', $token);
        $msg = "✅ Aprovado! A troca de bancos ocorrerá em instantes.";
    } elseif ($action === 'REJECT') {
        // Marca como rejeitado e limpa token
        $pdo->prepare("UPDATE monitoramento_rfb SET status = 'REJECTED', approval_token = NULL WHERE id = ?")->execute([$deploy['id']]);
        
        // Drop Temp DB (Opcional, ou deixa pro cron limpar)
        $dbTemp = getenv('DB_NAME') . '_temp';
        $pdo->exec("DROP DATABASE IF EXISTS `$dbTemp`");
        
        $msg = "❌ Rejeitado. Banco temporário excluído.";
    }
}

// Estatísticas do Banco Temp (Para ajudar na decisão)
$stats = [];
try {
    $dbTemp = getenv('DB_NAME') . '_temp';
    $res = $pdo->query("SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = '$dbTemp'");
    $stats = $res->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = [];
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aprovação de Deploy - CNPJ</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f4f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 500px; width: 100%; }
        h1 { margin-top: 0; color: #333; }
        .stats { background: #f8f9fa; padding: 1rem; border-radius: 8px; margin: 1.5rem 0; }
        .stats table { width: 100%; border-collapse: collapse; }
        .stats td { padding: 4px; border-bottom: 1px solid #ddd; }
        .btn { display: block; width: 100%; padding: 12px; margin: 10px 0; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; font-weight: bold; }
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .alert { padding: 1rem; background: #d4edda; color: #155724; border-radius: 6px; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($msg): ?>
            <div class="alert"><?= $msg ?></div>
        <?php else: ?>
            <h1>🚀 Aprovar Nova Versão?</h1>
            <p>A importação da pasta <b><?= $deploy['pasta_rfb'] ?></b> foi concluída no banco temporário.</p>
            
            <div class="stats">
                <h3>📊 Estatísticas (Temp DB)</h3>
                <?php if (empty($stats)): ?>
                    <p>Não foi possível ler estatísticas.</p>
                <?php else: ?>
                    <table>
                        <?php foreach($stats as $row): ?>
                        <tr>
                            <td><?= $row['table_name'] ?></td>
                            <td align="right"><?= number_format($row['table_rows'], 0, ',', '.') ?> regs</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <form method="POST">
                <button type="submit" name="action" value="APPROVE" class="btn btn-approve">✅ Aprovar e Trocar (Swap)</button>
                <button type="submit" name="action" value="REJECT" class="btn btn-reject">❌ Rejeitar e Excluir</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
