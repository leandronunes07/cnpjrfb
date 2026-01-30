<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CNPJ Extractor v3.0 | Ultimate Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="//unpkg.com/alpinejs" defer></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Inter:wght@400;600;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .glass-panel { background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(12px); border: 1px solid rgba(51, 65, 85, 0.5); }
        .neon-text-green { text-shadow: 0 0 10px rgba(16, 185, 129, 0.5); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 h-screen overflow-hidden flex flex-col" x-data="dashboard()">

    <!-- Top Bar -->
    <header class="z-20 glass-panel border-b border-slate-800 h-16 flex items-center justify-between px-6 shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse neon-text-green"></div>
            <h1 class="text-xl font-bold tracking-tight text-white uppercase">CNPJ<span class="text-emerald-500">Extractor</span> <span class="text-xs bg-slate-800 px-2 py-0.5 rounded text-slate-400">v3.0 ULTIMATE</span></h1>
        </div>
        <div class="flex gap-4">
            <button @click="installCron()" class="text-xs bg-slate-800 hover:bg-slate-700 px-3 py-1 rounded border border-slate-700 flex items-center gap-2">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Instalar Cron
            </button>
            <div class="flex items-center gap-2 text-sm font-mono text-slate-400">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Conectado
                <span x-text="timestamp"></span>
            </div>
        </div>
    </header>

    <!-- Main Layout -->
    <div class="flex flex-1 overflow-hidden">
        
        <!-- Sidebar Navigation -->
        <nav class="w-64 glass-panel border-r border-slate-800 flex flex-col p-4 gap-2">
            <a href="#" @click.prevent="tab = 'monitor'" :class="{'bg-emerald-500/10 text-emerald-400 border-emerald-500/20': tab === 'monitor', 'hover:bg-slate-800 text-slate-400': tab !== 'monitor'}" class="px-4 py-3 rounded border border-transparent transition-all font-semibold flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                Monitor
            </a>
            <a href="#" @click.prevent="loadLogs()" :class="{'bg-emerald-500/10 text-emerald-400 border-emerald-500/20': tab === 'logs', 'hover:bg-slate-800 text-slate-400': tab !== 'logs'}" class="px-4 py-3 rounded border border-transparent transition-all font-semibold flex items-center gap-3">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Logs do Sistema
            </a>
            <div class="mt-auto p-4 bg-slate-900 rounded border border-slate-800 text-xs text-slate-500">
                <p class="font-bold text-slate-400 mb-1">Status do Cron</p>
                <div x-html="cronStatus" class="font-mono">Verificando...</div>
                
                <button @click="runDiscovery()" class="mt-2 w-full text-xs bg-blue-900/50 hover:bg-blue-800 border border-blue-800 text-blue-200 px-2 py-1 rounded">
                    ▶ Rodar Descoberta Agora
                </button>
            </div>
        </nav>

        <!-- Content Area -->
        <main class="flex-1 overflow-auto bg-slate-900/50 p-8 relative">
            
            <!-- Tab: Monitor -->
            <div x-show="tab === 'monitor'" class="space-y-6">
                
                <!-- KPIs -->
                <div class="grid grid-cols-4 gap-6">
                    <div class="glass-panel p-6 rounded-lg border-l-4 border-l-emerald-500">
                        <h3 class="text-xs uppercase font-bold text-slate-500">Concluídos</h3>
                        <div class="text-3xl font-mono font-bold text-white mt-1" x-text="metrics.completed || 0">0</div>
                    </div>
                    <div class="glass-panel p-6 rounded-lg border-l-4 border-l-blue-500">
                        <h3 class="text-xs uppercase font-bold text-slate-500">Linhas Processadas</h3>
                        <div class="text-3xl font-mono font-bold text-white mt-1" x-text="formatNumber(metrics.total_rows || 0)">0</div>
                    </div>
                    <div class="glass-panel p-6 rounded-lg border-l-4 border-l-amber-500">
                        <h3 class="text-xs uppercase font-bold text-slate-500">Ativos Agora</h3>
                        <div class="text-3xl font-mono font-bold text-amber-400 mt-1" x-text="metrics.active || 0">0</div>
                        <div class="text-xs text-amber-500/70 animate-pulse">Live Worker Threads</div>
                    </div>
                    <div class="glass-panel p-6 rounded-lg border-l-4 border-l-rose-500">
                        <h3 class="text-xs uppercase font-bold text-slate-500">Erros</h3>
                        <div class="text-3xl font-mono font-bold text-rose-500 mt-1" x-text="metrics.errors || 0">0</div>
                    </div>
                </div>

                <!-- Active Table -->
                <div class="glass-panel rounded-lg overflow-hidden border border-slate-700">
                    <div class="bg-slate-800/50 px-6 py-4 border-b border-slate-700 flex justify-between items-center">
                        <h3 class="font-bold text-lg text-emerald-400">Processamento em Tempo Real</h3>
                        <span class="text-xs bg-slate-900 px-2 py-1 rounded text-slate-400 font-mono">Auto-Refresh: 2s</span>
                    </div>
                    <table class="w-full text-left text-sm font-mono">
                        <thead class="bg-slate-900 text-slate-500 text-xs uppercase">
                            <tr>
                                <th class="px-6 py-3">ID</th>
                                <th class="px-6 py-3">Arquivo</th>
                                <th class="px-6 py-3">Step</th>
                                <th class="px-6 py-3 text-right">Progresso (Rows)</th>
                                <th class="px-6 py-3 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <template x-for="job in activeJobs" :key="job.id">
                                <tr class="hover:bg-slate-800/50 transition">
                                    <td class="px-6 py-4 text-slate-500" x-text="'#' + job.id"></td>
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-white" x-text="job.file_name"></div>
                                        <div class="text-xs text-slate-500" x-text="job.type"></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 rounded text-xs font-bold"
                                            :class="{
                                                'bg-blue-900/30 text-blue-400': job.status === 'DOWNLOADING',
                                                'bg-purple-900/30 text-purple-400': job.status === 'EXTRACTING',
                                                'bg-amber-900/30 text-amber-400': job.status === 'IMPORTING'
                                            }" x-text="job.status"></span>
                                    </td>
                                    <td class="px-6 py-4 text-right text-white" x-text="formatNumber(job.rows_processed)"></td>
                                    <td class="px-6 py-4 text-right">
                                        <button @click="resetJob(job.id)" class="text-xs text-rose-500 hover:text-rose-300 underline">Abortar</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="activeJobs.length === 0">
                                <td colspan="5" class="px-6 py-12 text-center text-slate-600">
                                    Nenhum worker ativo no momento.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab: Logs -->
            <div x-show="tab === 'logs'" class="h-full flex flex-col" x-cloak>
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-bold">Logs do Sistema</h3>
                    <button @click="loadLogs()" class="bg-slate-800 px-3 py-1 rounded text-sm hover:bg-slate-700">Atualizar Logs</button>
                </div>
                <div class="bg-black/80 rounded-lg p-4 font-mono text-xs text-slate-300 overflow-auto flex-1 whitespace-pre-wrap border border-slate-700 shadow-inner" x-ref="logViewer">
                    <template x-for="line in logs" :key="line">
                        <div class="py-0.5 border-b border-white/5 hover:bg-white/5" x-text="line"></div>
                    </template>
                    <div x-show="logs.length === 0" class="text-slate-600 italic">Nenhum log encontrado ou arquivo vazio.</div>
                </div>
            </div>

        </main>
    </div>

    <!-- Notification Toast -->
    <div x-show="notification.show" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-6 right-6 px-6 py-4 rounded-lg shadow-2xl border flex items-center gap-3 z-50"
         :class="{'bg-emerald-900/90 border-emerald-500 text-emerald-200': notification.type === 'success', 'bg-rose-900/90 border-rose-500 text-rose-200': notification.type === 'error'}"
         x-cloak>
        <span x-text="notification.message"></span>
    </div>

    <script>
        function dashboard() {
            return {
                tab: 'monitor',
                metrics: {},
                activeJobs: [],
                logs: [],
                cronStatus: 'Aguardando verificação...',
                timestamp: '',
                notification: { show: false, message: '', type: 'success' },

                init() {
                    this.fetchStats();
                    this.checkCron();
                    setInterval(() => this.updateClock(), 1000);
                    setInterval(() => {
                        if (this.tab === 'monitor') this.fetchStats();
                    }, 2000);
                },

                updateClock() {
                    const now = new Date();
                    this.timestamp = now.toLocaleTimeString('pt-BR') + ' UTC';
                },

                async checkCron() {
                    try {
                        const res = await fetch('api.php?action=cron_status');
                        const data = await res.json();
                        if (data.status === 'ok') {
                            if (data.installed) {
                                let statusHtml = '<div class="flex flex-col gap-1">';
                                statusHtml += '<span class="text-emerald-500 font-bold flex items-center gap-1">✅ Instalado</span>';
                                
                                if (data.running > 0) {
                                    statusHtml += `<span class="text-amber-400 text-[10px] animate-pulse">⚡ ${data.running} Worker(s) Rodando</span>`;
                                } else {
                                    statusHtml += '<span class="text-slate-500 text-[10px]">💤 Aguardando agendador...</span>';
                                }
                                statusHtml += '</div>';
                                this.cronStatus = statusHtml;
                            } else {
                                this.cronStatus = '<span class="text-rose-500 font-bold underline cursor-pointer" @click="installCron()">⚠️ Não Instalado</span>';
                            }
                        }
                    } catch (e) {
                        this.cronStatus = '<span class="text-slate-500">Erro ao verificar</span>';
                    }
                },
                
                async runDiscovery() {
                    this.showNotify('Iniciando Descoberta...', 'info');
                    try {
                        const res = await fetch('api.php?action=run_discovery');
                        
                        // Check content type before parsing
                        const contentType = res.headers.get("content-type");
                        if (!contentType || !contentType.includes("application/json")) {
                             const text = await res.text();
                             console.error("Raw Response:", text);
                             this.showNotify('Erro: Resposta malformada do servidor', 'error');
                             alert("Server Error (Not JSON):\n" + text);
                             return;
                        }

                        const data = await res.json();
                        if (data.status === 'ok') {
                            this.showNotify('Descoberta concluída! ' + data.message, 'success');
                            this.fetchStats(); 
                            this.loadLogs();
                        } else {
                             console.error("Discovery Error Debug:", data);
                             this.showNotify('Erro: ' + data.message, 'error');
                             if (data.output) alert("Detalhes do Erro:\n" + data.output);
                        }
                    } catch (e) {
                        console.error(e);
                        this.showNotify('Falha Crítica na Requisição: ' + e.message, 'error');
                    }
                },

                async fetchStats() {
                    try {
                        const res = await fetch('api.php?action=stats');
                        const data = await res.json();
                        if (data.status === 'ok') {
                            this.metrics = data.metrics;
                            this.activeJobs = data.active;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                },

                async loadLogs() {
                    this.tab = 'logs';
                    try {
                        const res = await fetch('api.php?action=logs');
                        const data = await res.json();
                        if (data.status === 'ok') {
                            this.logs = data.logs;
                        }
                    } catch (e) {
                        this.showNotify('Erro ao carregar logs', 'error');
                    }
                },

                async resetJob(id) {
                    if (!confirm('Deseja realmente reiniciar este job?')) return;
                    try {
                        const res = await fetch('api.php?action=reset', {
                            method: 'POST',
                            body: JSON.stringify({ id: id })
                        });
                        const data = await res.json();
                        if (data.status === 'ok') {
                            this.fetchStats();
                            this.showNotify('Job reiniciado com sucesso!', 'success');
                        }
                    } catch (e) {
                        this.showNotify('Erro ao reiniciar job', 'error');
                    }
                },

                async installCron() {
                    try {
                        const res = await fetch('api.php?action=cron_install');
                        const data = await res.json();
                        if (data.status === 'ok') {
                            this.showNotify(data.message, 'success');
                            this.cronStatus = 'Instalado ✅';
                        } else {
                            // Show manual command
                            prompt(data.message + "\nCopie o comando abaixo:", data.command);
                            this.showNotify('Instalação manual requerida', 'error');
                        }
                    } catch (e) {
                        this.showNotify('Erro ao verificar Cron', 'error');
                    }
                },

                showNotify(msg, type = 'success') {
                    this.notification = { show: true, message: msg, type: type };
                    setTimeout(() => { this.notification.show = false }, 3000);
                },

                formatNumber(num) {
                    return new Intl.NumberFormat('pt-BR').format(num);
                }
            }
        }
    </script>
</body>
</html>
