<?php
// index.php
require_once 'config.php';
require_once 'DatabaseManager.php';

// --- HANDLE AJAX GET DATABASES (TANPA LOGIN FULL) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_dbs') {
    header('Content-Type: application/json');
    try {
        $host = $_POST['host'] ?? 'localhost';
        $user = $_POST['user'] ?? 'root';
        $pass = $_POST['pass'] ?? '';
        
        $tempPdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
        $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dbs = $tempPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode(['success' => true, 'dbs' => $dbs]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- HANDLE DISCONNECT ---
if (isset($_GET['action']) && $_GET['action'] === 'disconnect') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// --- HANDLE CONNECTION FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['connect_mysql'])) {
        $_SESSION['db_connection'] = [
            'driver' => 'mysql',
            'host' => $_POST['host'] ?? 'localhost',
            'user' => $_POST['user'] ?? 'root',
            'pass' => $_POST['pass'] ?? '',
            'dbname' => $_POST['dbname'] ?? 'db_voidbraver'
        ];
        header("Location: index.php");
        exit;
    } elseif (isset($_POST['connect_sqlite']) && isset($_FILES['sqlite_file'])) {
        $file = $_FILES['sqlite_file'];
        
        if ($file['error'] === UPLOAD_ERR_OK) {
            $dest = __DIR__ . '/temp_sqlite_' . time() . '.db';
            if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                $dest = sys_get_temp_dir() . '/temp_sqlite_' . time() . '.db';
                if (!@move_uploaded_file($file['tmp_name'], $dest)) {
                    $conn_error = "Gagal menyimpan file upload. Pastikan folder server memiliki izin Write.";
                }
            }

            if (!isset($conn_error)) {
                $_SESSION['db_connection'] = [
                    'driver' => 'sqlite',
                    'file' => $dest,
                    'dbname' => basename($file['name'])
                ];
                header("Location: index.php");
                exit;
            }
        } else {
            $errCodes = [
                UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi batas upload_max_filesize di php.ini.',
                UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian.',
                UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload.'
            ];
            $conn_error = "Upload Gagal: " . ($errCodes[$file['error']] ?? "Error Code {$file['error']}");
        }
    }
}

// --- TAMPILAN LOGIN / KONEKSI ---
if ($pdo === null):
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voidbraver DB - Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0f172a; color: #e2e8f0; }
        .glass-card { background: rgba(30, 41, 59, 0.8); backdrop-filter: blur(20px); border: 1px solid #334155; }
    </style>
</head>
<body class="h-screen w-full flex items-center justify-center p-4 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')]">
    <div class="glass-card max-w-4xl w-full rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row h-auto max-h-[90vh]">
        
        <div class="flex-1 p-8 border-b md:border-b-0 md:border-r border-slate-700 relative overflow-y-auto">
            <h2 class="text-2xl font-bold text-blue-500 mb-2"><i class="fa-solid fa-server mr-2"></i> MySQL Server</h2>
            <p class="text-sm text-slate-400 mb-6">Hubungkan ke database lokal atau remote.</p>
            
            <?php if(isset($conn_error)): ?>
                <div class="bg-red-500/10 border border-red-500 text-red-400 p-3 rounded-lg text-xs mb-4">
                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($conn_error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" id="mysqlForm">
                <input type="hidden" name="connect_mysql" value="1">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1">Host</label>
                        <input type="text" name="host" id="host" value="localhost" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-400 mb-1">User</label>
                        <input type="text" name="user" id="user" value="root" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:border-blue-500 outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-400 mb-1">Password</label>
                    <input type="password" name="pass" id="pass" placeholder="(Kosongkan jika XAMPP)" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-sm focus:border-blue-500 outline-none">
                </div>
                <div>
                    <div class="flex justify-between items-end mb-1">
                        <label class="block text-xs font-bold text-blue-400">Pilih Database</label>
                        <button type="button" onclick="loadDatabases()" id="btnLoadDb" class="text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-2 py-1 rounded transition-colors"><i class="fa-solid fa-rotate mr-1"></i> Load List</button>
                    </div>
                    
                    <input type="text" name="dbname" id="dbInput" value="db_voidbraver" required class="w-full bg-blue-900/20 border border-blue-500/50 rounded-lg px-4 py-2 text-sm focus:border-blue-500 outline-none text-blue-300 font-bold tracking-wide">
                    <select id="dbSelect" class="w-full bg-blue-900/20 border border-blue-500/50 rounded-lg px-4 py-2 text-sm focus:border-blue-500 outline-none text-blue-300 font-bold hidden"></select>
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 px-4 rounded-lg transition-colors mt-2">
                    Connect MySQL <i class="fa-solid fa-arrow-right ml-1"></i>
                </button>
            </form>
        </div>

        <div class="flex-1 p-8 bg-slate-800/50 flex flex-col justify-center">
            <h2 class="text-2xl font-bold text-emerald-500 mb-2"><i class="fa-solid fa-file-code mr-2"></i> SQLite File</h2>
            <p class="text-sm text-slate-400 mb-6">Unggah file .sqlite atau .db untuk diinspeksi.</p>
            
            <form method="POST" enctype="multipart/form-data" class="flex flex-col h-full" id="sqliteForm">
                <input type="hidden" name="connect_sqlite" value="1">
                
                <div class="flex-1 border-2 border-dashed border-slate-600 rounded-xl flex flex-col items-center justify-center p-8 text-center hover:border-emerald-500 hover:bg-emerald-500/5 transition-colors group cursor-pointer relative min-h-[250px]">
                    <input type="file" name="sqlite_file" accept=".sqlite,.db,.sqlite3" required class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10" 
                    onchange="
                        document.getElementById('uploadIcon').className = 'fa-solid fa-circle-notch fa-spin text-5xl text-emerald-500 mb-4'; 
                        document.getElementById('fileName').innerText = 'Memuat Database...'; 
                        document.getElementById('fileDesc').innerText = 'Mohon tunggu sebentar';
                        this.form.submit();
                    ">
                    
                    <i id="uploadIcon" class="fa-solid fa-cloud-arrow-up text-5xl text-slate-500 group-hover:text-emerald-500 mb-4 transition-colors"></i>
                    <p class="text-base font-bold text-slate-300 group-hover:text-emerald-400" id="fileName">Klik atau Drop file SQLite ke sini</p>
                    <p class="text-sm text-slate-500 mt-2" id="fileDesc">Langsung otomatis terbuka</p>
                </div>
            </form>
        </div>
    </div>

    <script>
        async function loadDatabases() {
            const btn = document.getElementById('btnLoadDb');
            const host = document.getElementById('host').value;
            const user = document.getElementById('user').value;
            const pass = document.getElementById('pass').value;
            
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';
            
            const formData = new FormData();
            formData.append('host', host);
            formData.append('user', user);
            formData.append('pass', pass);

            try {
                const res = await fetch('?action=get_dbs', { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.success) {
                    const select = document.getElementById('dbSelect');
                    select.innerHTML = '';
                    let foundVoid = false;
                    data.dbs.forEach(db => {
                        const opt = document.createElement('option');
                        opt.value = db;
                        opt.innerText = db;
                        if (db === 'db_voidbraver') { opt.selected = true; foundVoid = true; }
                        select.appendChild(opt);
                    });
                    
                    if(!foundVoid && data.dbs.length > 0) select.selectedIndex = 0;
                    
                    select.classList.remove('hidden');
                    document.getElementById('dbInput').classList.add('hidden');
                    document.getElementById('dbInput').name = '';
                    select.name = 'dbname'; 
                } else {
                    alert("Koneksi gagal: " + data.message);
                }
            } catch (e) {
                alert("Error: " + e.message);
            }
            btn.innerHTML = '<i class="fa-solid fa-rotate"></i> Load List';
        }
    </script>
</body>
</html>
<?php 
exit; 
endif; 

// --- DASHBOARD UTAMA (SETELAH LOGIN) ---

$dbManager = new DatabaseManager($pdo, $dbname, $driver);

if (isset($_GET['action']) && $_GET['action'] === 'trace') {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    try {
        $traceMode = $_GET['trace_mode'] ?? 'raw';
        $res = $dbManager->traceValueAdvanced($_GET['table'], $_GET['col'], $_GET['val'], $traceMode);
        echo json_encode(['error' => false, 'data' => $res]);
    } catch (Exception $e) {
        echo json_encode(['error' => true, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && ($_GET['action'] === 'sqlite' || $_GET['action'] === 'sqlite_all')) {
    $file = ($_GET['action'] === 'sqlite_all') 
            ? $dbManager->exportFullDatabaseToSQLite() : $dbManager->exportToSQLite($_GET['table']);
    if ($file && file_exists($file)) {
        $filename = ($_GET['action'] === 'sqlite_all') ? "{$dbname}_FULL_Backup.sqlite" : "{$_GET['table']}_backup.sqlite";
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: ' . filesize($file));
        readfile($file); unlink($file); exit;
    }
    die("Gagal mengekspor data.");
}

$tables = $dbManager->getTables();
$relations = $dbManager->getRelations();

$currentTable = $_GET['table'] ?? (!empty($tables) ? $tables[0] : null);
$mode = $_GET['mode'] ?? 'join';
$query = "";
$rows = [];

if ($currentTable) {
    $query = ($mode === 'raw') ? $dbManager->buildRawQuery($currentTable) : $dbManager->buildJoinQuery($currentTable, $relations);
    $result = $dbManager->fetchData($query);
    if (!isset($result['error'])) {
        $rows = $result;
        if (empty($rows) && $mode === 'join') {
            $query = $dbManager->buildRawQuery($currentTable);
            $rows = $dbManager->fetchData($query);
            $mode = 'raw';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($dbname) ?> - DB Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');
        :root { --theme-color: #3b82f6; --theme-color-hover: #2563eb; --theme-bg-subtle: rgba(59, 130, 246, 0.1); --bg-base: #0f172a; --bg-panel: #1e293b; --border-color: #334155; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-base); color: #e2e8f0; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .text-theme { color: var(--theme-color) !important; }
        .bg-theme { background-color: var(--theme-color) !important; }
        .border-theme { border-color: var(--theme-color) !important; }
        .hover-text-theme:hover { color: var(--theme-color) !important; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--theme-color); }
        .table-wrapper { height: calc(100vh - 200px); }
        th { position: sticky; top: 0; z-index: 20; }
        .sort-icon { opacity: 0.2; transition: all 0.2s; font-size: 0.7rem; margin-left: 6px; }
        th.asc .sort-icon { opacity: 1; color: var(--theme-color); transform: rotate(180deg); }
        th.desc .sort-icon { opacity: 1; color: var(--theme-color); }
        td { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); position: relative; }
        td:hover { background: var(--theme-bg-subtle); color: var(--theme-color); cursor: pointer; }
        td::after { content: "\f002 Inspect"; font-family: 'Plus Jakarta Sans', 'Font Awesome 6 Free'; font-weight: 700; position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%) translateY(5px); background: var(--theme-color); color: white; padding: 4px 10px; border-radius: 6px; font-size: 11px; opacity: 0; visibility: hidden; pointer-events: none; transition: all 0.2s ease; white-space: nowrap; z-index: 50; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3); }
        td::before { content: ""; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%) translateY(5px); border-width: 4px; border-style: solid; border-color: var(--theme-color) transparent transparent transparent; opacity: 0; visibility: hidden; transition: all 0.2s ease; z-index: 50; }
        td:hover::after, td:hover::before { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
        .glass-panel { background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(16px); border: 1px solid var(--border-color); }
        .sidebar-link.active { background: var(--theme-bg-subtle); border-right: 3px solid var(--theme-color); color: var(--theme-color); font-weight: 600; }
    </style>
    <style id="dynamicPinStyle"></style>
</head>
<body class="flex overflow-hidden antialiased">

    <div id="settingsModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div class="bg-[#1e293b] border border-slate-700 w-full max-w-md rounded-xl shadow-2xl flex flex-col overflow-hidden">
            <div class="p-5 border-b border-slate-700/50 flex justify-between items-center bg-slate-800/50">
                <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fa-solid fa-gear text-slate-400"></i> Konfigurasi Sistem</h3>
                <button onclick="toggleSettings()" class="text-slate-400 hover:text-red-400"><i class="fa-solid fa-xmark fa-lg"></i></button>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i class="fa-solid fa-palette mr-1"></i> Aksen Warna Tema</label>
                    <div class="flex gap-4">
                        <button onclick="setTheme('blue', '#3b82f6', '#2563eb')" class="w-10 h-10 rounded-full bg-blue-500 hover:scale-110 ring-2 ring-transparent focus:ring-blue-300"></button>
                        <button onclick="setTheme('emerald', '#10b981', '#059669')" class="w-10 h-10 rounded-full bg-emerald-500 hover:scale-110 ring-2 ring-transparent focus:ring-emerald-300"></button>
                        <button onclick="setTheme('violet', '#8b5cf6', '#7c3aed')" class="w-10 h-10 rounded-full bg-violet-500 hover:scale-110 ring-2 ring-transparent focus:ring-violet-300"></button>
                        <button onclick="setTheme('rose', '#f43f5e', '#e11d48')" class="w-10 h-10 rounded-full bg-rose-500 hover:scale-110 ring-2 ring-transparent focus:ring-rose-300"></button>
                        <button onclick="setTheme('amber', '#f59e0b', '#d97706')" class="w-10 h-10 rounded-full bg-amber-500 hover:scale-110 ring-2 ring-transparent focus:ring-amber-300"></button>
                    </div>
                </div>
                <div>
                    <label class="text-xs text-slate-400 block mb-3 uppercase font-bold tracking-wider"><i class="fa-solid fa-hard-drive mr-1"></i> Database Backup</label>
                    <a href="?action=sqlite_all" class="w-full py-3 px-4 bg-slate-700 hover:bg-slate-600 border border-slate-600 rounded-lg text-white font-medium flex items-center justify-center gap-2">
                        <i class="fa-solid fa-file-arrow-down text-theme"></i> Unduh Full DB (.sqlite)
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div id="tracerModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm transition-opacity">
        <div class="glass-panel w-full max-w-7xl h-[90vh] rounded-xl shadow-2xl flex flex-col overflow-hidden transform scale-95 transition-transform" id="tracerContent">
            <div class="p-4 border-b border-slate-700/50 flex flex-col gap-4 bg-slate-800/80">
                <div class="flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <button id="btnBackTracer" onclick="goBackTracer()" class="hidden px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white rounded-md transition-colors text-sm font-medium"><i class="fa-solid fa-arrow-left mr-1"></i> Kembali</button>
                        <div>
                            <h3 class="text-lg font-bold text-white flex items-center gap-2"><i class="fa-solid fa-network-wired text-theme"></i> Deep Data Inspector</h3>
                            <div id="tracerBreadcrumbs" class="flex items-center gap-2 mt-1 text-xs text-slate-400 font-mono overflow-x-auto max-w-3xl whitespace-nowrap"></div>
                        </div>
                    </div>
                    <button onclick="closeTracer()" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:bg-slate-700 hover:text-white"><i class="fa-solid fa-xmark fa-lg"></i></button>
                </div>
                <div class="flex justify-between items-center bg-slate-900/50 p-2 rounded-lg border border-slate-700/50">
                    <div class="flex gap-2 text-sm" id="inspectorFilters">
                        <button class="px-3 py-1.5 rounded-md bg-theme text-white font-medium filter-btn" data-filter="all"><i class="fa-solid fa-layer-group mr-1"></i> Semua</button>
                        <button class="px-3 py-1.5 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-2 items-center" data-filter="source_row"><i class="fa-solid fa-crosshairs"></i> Data Utama <span id="badge-source" class="bg-slate-800 text-xs px-1.5 rounded">0</span></button>
                        <button class="px-3 py-1.5 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-2 items-center" data-filter="row_relations"><i class="fa-solid fa-link"></i> Relasi <span id="badge-row" class="bg-slate-800 text-xs px-1.5 rounded">0</span></button>
                        <button class="px-3 py-1.5 rounded-md text-slate-400 hover:bg-slate-700 filter-btn flex gap-2 items-center" data-filter="global_matches"><i class="fa-solid fa-globe"></i> Global <span id="badge-global" class="bg-slate-800 text-xs px-1.5 rounded">0</span></button>
                    </div>
                    <div class="flex bg-slate-800 rounded-md p-1 border border-slate-700">
                        <button onclick="changeTracerMode('raw')" id="tracerModeRaw" class="px-3 py-1 rounded text-xs font-bold bg-theme text-white"><i class="fa-solid fa-table mr-1"></i> Raw</button>
                        <button onclick="changeTracerMode('join')" id="tracerModeJoin" class="px-3 py-1 rounded text-xs font-bold text-slate-400 hover:text-white"><i class="fa-solid fa-object-group mr-1"></i> Join</button>
                    </div>
                </div>
            </div>
            <div class="p-6 overflow-y-auto flex-1 font-mono text-sm space-y-6 bg-[#0f172a]" id="tracerBody"></div>
        </div>
    </div>

    <aside class="w-64 bg-[#1e293b] border-r border-slate-700 flex flex-col z-40 shrink-0 shadow-xl">
        <div class="p-6 border-b border-slate-700 flex justify-between items-center bg-[#1e293b]">
            <h1 class="text-xl font-black text-white tracking-wide flex items-center gap-2">
                <i class="fa-solid fa-database text-theme"></i> VOID<span class="text-slate-500 font-light">DB</span>
            </h1>
            <button onclick="toggleSettings()" class="text-slate-400 hover:text-theme transition-colors"><i class="fa-solid fa-gear fa-lg"></i></button>
        </div>
        
        <div class="px-6 py-4 border-b border-slate-700 bg-slate-800/30">
            <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">Active Connection</p>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2 text-sm text-slate-300 font-mono truncate">
                    <i class="fa-solid <?= $driver === 'mysql' ? 'fa-server text-blue-400' : 'fa-file-code text-emerald-400' ?>"></i>
                    <span class="truncate" title="<?= htmlspecialchars($dbname) ?>"><?= htmlspecialchars($dbname) ?></span>
                </div>
                <a href="?action=disconnect" class="text-red-400 hover:text-red-300 ml-2" title="Disconnect / Ganti Database">
                    <i class="fa-solid fa-power-off"></i>
                </a>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto py-4 space-y-1">
            <div class="px-6 mb-2 text-[10px] font-bold text-slate-500 uppercase tracking-widest">Daftar Tabel</div>
            <?php foreach ($tables as $t): ?>
                <a href="?table=<?= urlencode($t) ?>&mode=<?= $mode ?>" class="sidebar-link block px-6 py-2.5 text-sm transition-all duration-200 <?= $t === $currentTable ? 'active' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200' ?>">
                    <i class="fa-solid fa-table-cells text-slate-500 mr-2 opacity-70"></i> <?= htmlspecialchars($t) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <main class="flex-1 flex flex-col min-w-0 bg-[#0f172a] relative">
        <header class="bg-[#1e293b] border-b border-slate-700 shadow-sm z-40 relative">
            <div class="p-5 flex flex-col lg:flex-row justify-between lg:items-center gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-theme/10 border border-theme/20 flex items-center justify-center text-theme"><i class="fa-solid fa-table-list fa-lg"></i></div>
                    <div>
                        <h2 class="text-xl font-bold text-white font-mono leading-none"><?= htmlspecialchars($currentTable) ?></h2>
                        <p class="text-xs text-slate-400 mt-1" id="rowCount"><i class="fa-solid fa-chart-simple mr-1"></i> <?= count($rows) ?> records loaded</p>
                    </div>
                </div>
                
                <div class="flex flex-wrap items-center gap-3">
                    <div class="relative group">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-500 group-focus-within:text-theme transition-colors text-sm"></i>
                        <input type="text" id="globalSearch" placeholder="Cari di tabel ini..." class="pl-9 pr-4 py-2 bg-slate-900 border border-slate-700 focus:border-theme rounded-lg text-sm outline-none text-slate-200 transition-all w-56 focus:w-72 shadow-inner">
                    </div>

                    <div class="relative group">
                        <button class="px-4 py-2 bg-slate-800 border border-slate-600 hover:border-slate-500 rounded-lg text-sm transition-colors flex items-center gap-2 text-slate-200 shadow-sm">
                            <i class="fa-solid fa-eye-slash text-slate-400"></i> Kolom
                        </button>
                        <div class="absolute right-0 mt-2 w-64 bg-slate-800 border border-slate-700 rounded-lg shadow-2xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all p-2 flex flex-col gap-1 max-h-72 overflow-y-auto" id="columnToggles"></div>
                    </div>

                    <a href="?action=sqlite&table=<?= urlencode($currentTable) ?>" class="px-4 py-2 bg-slate-800 border border-slate-600 hover-border-theme hover-text-theme text-slate-200 rounded-lg text-sm font-medium transition-all flex items-center gap-2 shadow-sm">
                        <i class="fa-solid fa-download text-slate-400 group-hover:text-theme"></i> .sqlite
                    </a>
                    
                    <div class="flex bg-slate-900 rounded-lg p-1 border border-slate-700 shadow-inner">
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=raw" class="px-4 py-1.5 rounded-md text-sm transition-colors <?= $mode === 'raw' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-white' ?>"><i class="fa-solid fa-cube mr-1"></i> Raw</a>
                        <a href="?table=<?= urlencode($currentTable) ?>&mode=join" class="px-4 py-1.5 rounded-md text-sm transition-colors <?= $mode === 'join' ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-500 hover:text-white' ?>"><i class="fa-solid fa-link mr-1"></i> Joined</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="p-6 flex-1 overflow-hidden">
            <div class="table-wrapper overflow-auto rounded-xl border border-slate-700 bg-[#1e293b] shadow-lg relative">
                <table class="w-full text-left border-collapse whitespace-nowrap" id="dataTable">
                    <?php if (!empty($rows)): ?>
                        <thead class="bg-slate-800 text-slate-300 text-xs uppercase tracking-wider select-none shadow-sm">
                            <tr id="tableHeaderRow">
                                <?php foreach (array_keys($rows[0]) as $index => $col): ?>
                                    <th class="border-b border-slate-700 font-semibold bg-slate-800 group">
                                        <div class="flex items-center justify-between px-5 py-3.5">
                                            <div class="flex items-center gap-1 cursor-pointer hover-text-theme flex-1 transition-colors" onclick="sortTable(<?= $index ?>, this)">
                                                <span class="flex-1"><?= htmlspecialchars($col) ?></span><i class="fa-solid fa-sort sort-icon"></i>
                                            </div>
                                            <button class="opacity-0 group-hover:opacity-100 w-6 h-6 flex items-center justify-center hover:bg-slate-700 rounded transition-all text-slate-400 hover-text-theme ml-2" onclick="pinColumn(<?= $index ?>)" title="Pin Kolom"><i class="fa-solid fa-thumbtack"></i></button>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-slate-700/50 font-mono text-slate-300">
                            <?php foreach ($rows as $row): ?>
                                <tr class="hover:bg-slate-800/80 transition-colors data-row">
                                    <?php 
                                    $colKeys = array_keys($row);
                                    $colIndex = 0;
                                    foreach ($row as $cell): 
                                        $colName = $colKeys[$colIndex];
                                    ?>
                                        <td class="py-3 px-5" onclick="startTrace('<?= htmlspecialchars($currentTable) ?>', '<?= htmlspecialchars($colName) ?>', '<?= htmlspecialchars($cell ?? '') ?>')">
                                            <?= ($cell === null) ? '<span class="text-slate-600 italic">NULL</span>' : htmlspecialchars($cell) ?>
                                        </td>
                                    <?php 
                                    $colIndex++;
                                    endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php else: ?>
                        <tbody>
                            <tr>
                                <td class="py-20 text-center text-slate-500">
                                    <i class="fa-solid fa-folder-open text-4xl mb-3 opacity-50"></i>
                                    <p>Tidak ada data di tabel ini.</p>
                                </td>
                            </tr>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </main>

    <script>
        // --- THEME ENGINE ---
        function toggleSettings() {
            const m = document.getElementById('settingsModal');
            m.classList.toggle('hidden'); m.classList.toggle('flex');
        }
        function setTheme(name, hexColor, hexHover) {
            const root = document.documentElement;
            root.style.setProperty('--theme-color', hexColor);
            root.style.setProperty('--theme-color-hover', hexHover);
            let c; if(/^#([A-Fa-f0-9]{3}){1,2}$/.test(hexColor)){
                c= hexColor.substring(1).split('');
                if(c.length== 3) c= [c[0], c[0], c[1], c[1], c[2], c[2]];
                c= '0x'+c.join('');
                root.style.setProperty('--theme-bg-subtle', 'rgba('+[(c>>16)&255, (c>>8)&255, c&255].join(',')+',0.1)');
            }
            localStorage.setItem('voidDbTheme', JSON.stringify({color: hexColor, hover: hexHover}));
        }
        const savedTheme = JSON.parse(localStorage.getItem('voidDbTheme'));
        if (savedTheme) setTheme('custom', savedTheme.color, savedTheme.hover);

        // --- COLUMNS HIDER ENGINE ---
        const colContainer = document.getElementById('columnToggles');
        if (colContainer) {
            document.querySelectorAll('#tableHeaderRow th').forEach((th, index) => {
                let colNameText = th.querySelector('.flex-1')?.innerText.trim() || `Kolom ${index}`;
                const label = document.createElement('label');
                label.className = "flex items-center gap-3 px-3 py-2 hover:bg-slate-700 rounded-md cursor-pointer text-sm text-slate-300 transition-colors border border-transparent hover:border-slate-600";
                label.innerHTML = `<input type="checkbox" checked class="rounded bg-slate-900 border-slate-600 text-theme focus:ring-theme w-4 h-4 cursor-pointer" onchange="toggleColumn(${index}, this.checked)"> <span class="truncate flex-1">${colNameText}</span>`;
                colContainer.appendChild(label);
            });
        }
        function toggleColumn(index, isVisible) {
            const n = index + 1;
            document.querySelectorAll(`#dataTable th:nth-child(${n}), #dataTable td:nth-child(${n})`).forEach(cell => { cell.style.display = isVisible ? '' : 'none'; });
            if (!isVisible && currentPinnedIndex === index) pinColumn(index);
        }

        // --- DYNAMIC PIN COLUMN FIX ---
        let currentPinnedIndex = -1;
        function pinColumn(index) {
            const nth = index + 1;
            const styleTag = document.getElementById('dynamicPinStyle');
            if (currentPinnedIndex === index) {
                styleTag.innerHTML = ''; currentPinnedIndex = -1;
            } else {
                styleTag.innerHTML = `
                    #dataTable th:nth-child(${nth}), #dataTable td:nth-child(${nth}) {
                        position: sticky !important; left: 0 !important; right: 0 !important; z-index: 30 !important;
                        background-color: var(--bg-panel) !important; box-shadow: -4px 0 15px rgba(0,0,0,0.5), 4px 0 15px rgba(0,0,0,0.5) !important;
                        border-left: 2px solid var(--theme-color) !important; border-right: 2px solid var(--theme-color) !important;
                    }
                    #dataTable td:nth-child(${nth}) { background-color: #1e293b !important; }
                    #dataTable th:nth-child(${nth}) { z-index: 40 !important; background-color: #1e293b !important; }
                `;
                currentPinnedIndex = index;
            }
        }

        function sortTable(n, headerElem) {
            const tbody = document.querySelector("#dataTable tbody");
            const rows = Array.from(tbody.querySelectorAll("tr.data-row"));
            document.querySelectorAll("#tableHeaderRow th").forEach(th => th.classList.remove('asc', 'desc'));
            let isAsc = headerElem.dataset.dir !== 'asc';
            headerElem.dataset.dir = isAsc ? 'asc' : 'desc';
            headerElem.closest('th').classList.add(isAsc ? 'asc' : 'desc');
            rows.sort((a, b) => {
                let x = a.children[n].innerText.trim(), y = b.children[n].innerText.trim();
                if(x === 'NULL') return isAsc ? -1 : 1;
                if(y === 'NULL') return isAsc ? 1 : -1;
                let numX = parseFloat(x), numY = parseFloat(y);
                if(!isNaN(numX) && !isNaN(numY)) return isAsc ? numX - numY : numY - numX;
                return isAsc ? x.localeCompare(y) : y.localeCompare(x);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        document.getElementById('globalSearch').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase(), count = 0;
            document.querySelectorAll("#dataTable tbody tr.data-row").forEach(row => {
                if(row.innerText.toLowerCase().includes(filter)) { row.style.display = ""; count++; }
                else row.style.display = "none";
            });
            document.getElementById('rowCount').innerHTML = `<i class="fa-solid fa-chart-simple mr-1"></i> ${count} records loaded`;
        });

        // --- ULTRA DEEP RECURSIVE TRACER ---
        const modal = document.getElementById('tracerModal');
        const tracerBody = document.getElementById('tracerBody');
        let traceHistory = [];
        let currentTraceData = null; 
        let currentTracerMode = 'raw'; 

        function changeTracerMode(mode) {
            currentTracerMode = mode;
            const actCls = 'px-3 py-1 rounded text-xs font-bold bg-theme text-white shadow-sm';
            const inactCls = 'px-3 py-1 rounded text-xs font-bold text-slate-400 hover:text-white transition-colors';
            document.getElementById('tracerModeRaw').className = mode === 'raw' ? actCls : inactCls;
            document.getElementById('tracerModeJoin').className = mode === 'join' ? actCls : inactCls;
            if(traceHistory.length > 0) {
                const last = traceHistory[traceHistory.length - 1];
                executeTrace(last.table, last.col, last.val, true); 
            }
        }

        function startTrace(tableName, colName, value) {
            if (value === 'NULL' || value === '') return;
            traceHistory = []; executeTrace(tableName, colName, value);
        }

        function pushTrace(tableName, colName, value) {
            if (value === 'NULL' || value === '') return;
            executeTrace(tableName, colName, value);
        }

        function goBackTracer() {
            if (traceHistory.length > 1) {
                traceHistory.pop(); 
                const prev = traceHistory[traceHistory.length - 1]; 
                executeTrace(prev.table, prev.col, prev.val, true);
            }
        }

        async function executeTrace(tableName, colName, value, skipPush = false) {
            modal.classList.remove('hidden'); modal.classList.add('flex');
            setTimeout(() => document.getElementById('tracerContent').classList.remove('scale-95'), 10);
            
            if(!skipPush) traceHistory.push({table: tableName, col: colName, val: value});
            document.getElementById('btnBackTracer').style.display = traceHistory.length > 1 ? 'block' : 'none';
            
            document.getElementById('tracerBreadcrumbs').innerHTML = traceHistory.map((step, i) => {
                return `<span class="${i === traceHistory.length - 1 ? 'text-theme font-bold' : 'text-slate-500'}">${step.table} (${step.col}=${step.val})</span>`;
            }).join(' <span class="text-slate-600 text-[10px]"><i class="fa-solid fa-chevron-right"></i></span> ');

            tracerBody.innerHTML = `<div class="flex flex-col gap-4 justify-center items-center p-20"><i class="fa-solid fa-circle-notch fa-spin text-4xl text-theme"></i><p class="text-slate-400">Menarik data dari database...</p></div>`;

            try {
                const response = await fetch(`?action=trace&table=${encodeURIComponent(tableName)}&col=${encodeURIComponent(colName)}&val=${encodeURIComponent(value)}&trace_mode=${currentTracerMode}`);
                const resData = await response.json();
                if(resData.error) throw new Error(resData.message);
                
                currentTraceData = resData.data;
                updateFilterBadges();
                renderTracerData('all'); 
            } catch (err) {
                tracerBody.innerHTML = `<div class="text-red-400 bg-red-900/20 border border-red-900 p-6 rounded-xl flex items-center gap-3"><i class="fa-solid fa-triangle-exclamation fa-2x"></i> Error: ${err.message}</div>`;
            }
        }

        function updateFilterBadges() {
            const d = currentTraceData;
            document.getElementById('badge-source').innerText = d.source_row.length;
            document.getElementById('badge-row').innerText = d.row_relations.length + d.value_relations.length;
            document.getElementById('badge-global').innerText = d.global_matches.length;
        }

        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.filter-btn').forEach(b => {
                    b.classList.remove('bg-theme', 'text-white', 'shadow-sm');
                    b.classList.add('text-slate-400', 'hover:bg-slate-700');
                });
                const target = e.currentTarget;
                target.classList.remove('text-slate-400', 'hover:bg-slate-700');
                target.classList.add('bg-theme', 'text-white', 'shadow-sm');
                renderTracerData(target.dataset.filter);
            });
        });

        function renderTracerData(filter) {
            if(!currentTraceData) return;
            const d = currentTraceData;
            let html = '';

            const renderBlock = (groups, sectionTitle, iconHtml, colorCls) => {
                if(groups.length === 0) return '';
                let blockHtml = `<h4 class="text-white font-bold mb-4 mt-6 flex items-center gap-2 px-2 text-base border-b border-slate-700 pb-2"><span class="text-${colorCls}">${iconHtml}</span> ${sectionTitle}</h4><div class="space-y-5 mb-8">`;
                groups.forEach(group => {
                    if(group.data.length === 0) return;
                    let keys = Object.keys(group.data[0]);
                    blockHtml += `
                        <div class="bg-[#1e293b] border border-slate-700 rounded-xl overflow-hidden shadow-md">
                            <div class="bg-slate-800 px-4 py-3 flex justify-between items-center border-b border-slate-700">
                                <span class="text-[10px] text-${colorCls} font-bold uppercase tracking-widest bg-slate-900 px-2.5 py-1 rounded border border-slate-700">${group.type}</span>
                                <span class="text-sm text-white font-bold tracking-wide flex items-center gap-2"><i class="fa-solid fa-table text-slate-500"></i> ${group.table} <span class="bg-slate-700 text-slate-300 text-[10px] px-2 py-0.5 rounded-full ml-1">${group.data.length} baris</span></span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-[#0f172a]/50 text-slate-400 text-xs">
                                        <tr>${keys.map(k => `<th class="px-4 py-3 font-semibold border-b border-slate-700 whitespace-nowrap">${k}</th>`).join('')}</tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-700/50 font-mono text-xs">
                                        ${group.data.map(row => `
                                            <tr class="hover:bg-slate-700/50 transition-colors group/row">
                                                ${Object.entries(row).map(([k, v]) => `
                                                    <td class="px-4 py-3 text-slate-300 cursor-pointer hover-text-theme whitespace-nowrap" onclick="pushTrace('${group.table}', '${k}', '${v}')">
                                                        ${v !== null ? v : '<span class="text-slate-600 italic">NULL</span>'}
                                                    </td>
                                                `).join('')}
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>`;
                });
                return blockHtml + `</div>`;
            };

            if (filter === 'all' || filter === 'source_row') {
                html += renderBlock(d.source_row, "Data Baris Utama (Entity Konteks)", '<i class="fa-solid fa-crosshairs"></i>', 'blue-400');
            }
            if (filter === 'all' || filter === 'row_relations') {
                html += renderBlock(d.row_relations, "Relasi Terhubung (ID Entitas)", '<i class="fa-solid fa-link"></i>', 'emerald-400');
                html += renderBlock(d.value_relations, "Relasi Berdasarkan Value Sel", '<i class="fa-solid fa-puzzle-piece"></i>', 'emerald-400');
            }
            if (filter === 'all' || filter === 'global_matches') {
                html += renderBlock(d.global_matches, "Ditemukan Juga Di (Pencarian Teks Global)", '<i class="fa-solid fa-globe"></i>', 'purple-400');
            }

            if(html === '') {
                html = `<div class="text-center p-12 bg-slate-800/50 rounded-xl border border-slate-700"><i class="fa-solid fa-box-open text-4xl text-slate-600 mb-4"></i><p class="text-slate-400">Tidak ada data untuk kategori ini.</p></div>`;
            }
            tracerBody.innerHTML = html;
        }

        function closeTracer() {
            document.getElementById('tracerContent').classList.add('scale-95');
            setTimeout(() => { modal.classList.add('hidden'); modal.classList.remove('flex'); }, 150);
        }
    </script>
</body>
</html>